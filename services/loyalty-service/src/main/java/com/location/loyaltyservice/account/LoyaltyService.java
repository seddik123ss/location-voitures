package com.location.loyaltyservice.account;

import com.location.loyaltyservice.common.ApiException;
import com.location.loyaltyservice.promo.DiscountType;
import com.location.loyaltyservice.promo.PromoCode;
import com.location.loyaltyservice.promo.PromoCodeRepository;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.security.SecureRandom;
import java.time.Clock;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/**
 * Règles de fidélité (cahier des charges, section 4) :
 * <ul>
 *   <li>100 points de bienvenue à l'ouverture (WELCOME) ;</li>
 *   <li>location terminée : floor(totalPrice / 10) x coefficient du niveau (BRONZE 1, SILVER 1.25, GOLD 1.5), arrondi
 *       à l'inférieur (EARN), une seule fois par réservation ;</li>
 *   <li>niveau calculé sur le cumul, jamais sur le solde : dépenser ne fait pas descendre ;</li>
 *   <li>conversion par multiples de 100 : 100 points = 10.000 TND, bon personnel LOY-XXXXXX, 1 utilisation, 90 jours.</li>
 * </ul>
 */
@Service
public class LoyaltyService {

    private static final Logger log = LoggerFactory.getLogger(LoyaltyService.class);

    public static final int WELCOME_POINTS = 100;
    public static final int REDEEM_STEP = 100;
    /** 100 points = 10.000 TND, donc 1 point = 0.100 TND. */
    public static final BigDecimal TND_PER_POINT = new BigDecimal("0.100");
    public static final int VOUCHER_VALIDITY_DAYS = 90;
    private static final String VOUCHER_CHARS = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    private static final SecureRandom RANDOM = new SecureRandom();

    private final LoyaltyAccountRepository accounts;
    private final PointTransactionRepository transactions;
    private final PromoCodeRepository promoCodes;
    private final Clock clock;

    public LoyaltyService(LoyaltyAccountRepository accounts, PointTransactionRepository transactions,
                          PromoCodeRepository promoCodes, Clock clock) {
        this.accounts = accounts;
        this.transactions = transactions;
        this.promoCodes = promoCodes;
        this.clock = clock;
    }

    /** Points gagnés pour une location : floor(totalPrice / 10) x coefficient, arrondi à l'inférieur. */
    public static int pointsFor(BigDecimal totalPrice, Tier tier) {
        if (totalPrice == null || totalPrice.signum() <= 0) {
            return 0;
        }
        BigDecimal base = totalPrice.divide(BigDecimal.TEN, 0, RoundingMode.FLOOR);
        return base.multiply(tier.coefficient()).setScale(0, RoundingMode.FLOOR).intValueExact();
    }

    // ---------------------------------------------------------------- lecture

    @Transactional(readOnly = true)
    public LoyaltyAccount getAccount(UUID userId) {
        return accounts.findById(userId)
                .orElseThrow(() -> ApiException.notFound("Aucun compte de fidélité pour l'utilisateur " + userId));
    }

    @Transactional(readOnly = true)
    public Page<LoyaltyAccount> listAccounts(Tier tier, int page, int size) {
        PageRequest pageable = PageRequest.of(page, size, Sort.by(Sort.Direction.DESC, "lifetimePoints"));
        return tier == null ? accounts.findAll(pageable) : accounts.findByTier(tier, pageable);
    }

    @Transactional(readOnly = true)
    public Page<PointTransaction> history(UUID userId, int page, int size) {
        getAccount(userId);
        return transactions.findByUserIdOrderByCreatedAtDesc(userId, PageRequest.of(page, size));
    }

    /** Bons personnels encore utilisables : actifs, non utilisés, non expirés. */
    @Transactional(readOnly = true)
    public List<PromoCode> vouchers(UUID userId) {
        getAccount(userId);
        LocalDate today = LocalDate.now(clock);
        return promoCodes.findByOwnerUserIdOrderByCreatedAtDesc(userId).stream()
                .filter(PromoCode::isActive)
                .filter(p -> !p.isExhausted())
                .filter(p -> !today.isAfter(p.getValidUntil()))
                .toList();
    }

    // ---------------------------------------------------------------- écriture

    /** Ouvre le compte avec 100 points de bienvenue. Déjà ouvert : rien ne change (renvoie false). */
    @Transactional
    public boolean openAccount(UUID userId) {
        if (accounts.existsById(userId)) {
            return false;
        }
        Instant now = Instant.now(clock);
        LoyaltyAccount account = LoyaltyAccount.open(userId, now);
        account.earn(WELCOME_POINTS, now);
        accounts.save(account);
        transactions.save(new PointTransaction(userId, TransactionType.WELCOME, WELCOME_POINTS,
                "Points de bienvenue", null, now));
        log.info("Compte de fidélité ouvert pour {} (+{} points)", userId, WELCOME_POINTS);
        return true;
    }

    /** Ferme le compte (utilisateur supprimé) et désactive ses bons personnels. Compte absent : renvoie false. */
    @Transactional
    public boolean closeAccount(UUID userId) {
        return accounts.findForUpdate(userId).map(account -> {
            Instant now = Instant.now(clock);
            account.close(now);
            promoCodes.findByOwnerUserIdOrderByCreatedAtDesc(userId).forEach(PromoCode::deactivate);
            log.info("Compte de fidélité de {} fermé, bons désactivés", userId);
            return true;
        }).orElse(false);
    }

    public enum EarnResult { EARNED, ALREADY_COUNTED, NO_ACTIVE_ACCOUNT, NOTHING_TO_EARN }

    /** Points d'une location terminée, comptés une seule fois par réservation. */
    @Transactional
    public EarnResult earnForReservation(UUID reservationId, UUID userId, BigDecimal totalPrice) {
        if (transactions.existsByTypeAndReservationId(TransactionType.EARN, reservationId)) {
            return EarnResult.ALREADY_COUNTED;
        }
        LoyaltyAccount account = accounts.findForUpdate(userId).orElse(null);
        if (account == null || !account.isActive()) {
            log.warn("Réservation {} terminée : pas de compte de fidélité actif pour {}, aucun point", reservationId, userId);
            return EarnResult.NO_ACTIVE_ACCOUNT;
        }
        Tier before = account.getTier();
        int points = pointsFor(totalPrice, before);
        if (points == 0) {
            return EarnResult.NOTHING_TO_EARN;
        }
        Instant now = Instant.now(clock);
        account.earn(points, now);
        transactions.save(new PointTransaction(userId, TransactionType.EARN, points,
                "Location terminée (" + totalPrice.setScale(3, RoundingMode.HALF_UP) + " TND, niveau " + before + ")",
                reservationId, now));
        log.info("Réservation {} : +{} points pour {} ({} -> {})", reservationId, points, userId, before, account.getTier());
        return EarnResult.EARNED;
    }

    /** Conversion de points en bon personnel LOY-XXXXXX. */
    @Transactional
    public PromoCode redeem(UUID userId, int points) {
        LoyaltyAccount account = accounts.findForUpdate(userId)
                .orElseThrow(() -> ApiException.notFound("Aucun compte de fidélité pour l'utilisateur " + userId));
        if (!account.isActive()) {
            throw ApiException.unprocessable("Le compte de fidélité est fermé", "userId");
        }
        if (points <= 0 || points % REDEEM_STEP != 0) {
            throw ApiException.unprocessable("La conversion se fait par multiples de " + REDEEM_STEP + " points", "points");
        }
        if (points > account.getBalance()) {
            throw ApiException.unprocessable("Solde insuffisant : " + account.getBalance() + " points disponibles", "points");
        }
        Instant now = Instant.now(clock);
        LocalDate today = LocalDate.now(clock);
        BigDecimal value = TND_PER_POINT.multiply(BigDecimal.valueOf(points)).setScale(3, RoundingMode.HALF_UP);
        PromoCode voucher = new PromoCode(newVoucherCode(), DiscountType.AMOUNT, value, null, null,
                today, today.plusDays(VOUCHER_VALIDITY_DAYS), 1, 1, userId, now);
        promoCodes.save(voucher);
        account.spend(points, now);
        transactions.save(new PointTransaction(userId, TransactionType.REDEEM, -points,
                "Conversion en bon " + voucher.getCode(), null, now));
        return voucher;
    }

    /** Geste commercial (positif : compte aussi dans le cumul) ou correction (négative : solde seulement). */
    @Transactional
    public LoyaltyAccount adjust(UUID userId, int points, String reason) {
        LoyaltyAccount account = accounts.findForUpdate(userId)
                .orElseThrow(() -> ApiException.notFound("Aucun compte de fidélité pour l'utilisateur " + userId));
        if (!account.isActive()) {
            throw ApiException.unprocessable("Le compte de fidélité est fermé", "userId");
        }
        if (points == 0) {
            throw ApiException.unprocessable("L'ajustement doit être différent de 0", "points");
        }
        if (points < 0 && -points > account.getBalance()) {
            throw ApiException.unprocessable("Solde insuffisant : " + account.getBalance() + " points disponibles", "points");
        }
        Instant now = Instant.now(clock);
        if (points > 0) {
            account.earn(points, now);
        } else {
            account.spend(-points, now);
        }
        transactions.save(new PointTransaction(userId, TransactionType.ADJUST, points, reason.trim(), null, now));
        return account;
    }

    private String newVoucherCode() {
        for (int attempt = 0; attempt < 20; attempt++) {
            StringBuilder code = new StringBuilder("LOY-");
            for (int i = 0; i < 6; i++) {
                code.append(VOUCHER_CHARS.charAt(RANDOM.nextInt(VOUCHER_CHARS.length())));
            }
            if (!promoCodes.existsByCode(code.toString())) {
                return code.toString();
            }
        }
        throw new IllegalStateException("Impossible de générer un code de bon unique");
    }
}
