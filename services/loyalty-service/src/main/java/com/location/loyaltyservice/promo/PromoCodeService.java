package com.location.loyaltyservice.promo;

import com.location.loyaltyservice.account.LoyaltyAccount;
import com.location.loyaltyservice.account.LoyaltyAccountRepository;
import com.location.loyaltyservice.account.Tier;
import com.location.loyaltyservice.common.ApiException;
import com.location.loyaltyservice.promo.dto.PromoCodeRequest;
import com.location.loyaltyservice.promo.dto.PromoCodeUpdateRequest;
import com.location.loyaltyservice.remote.RemoteServices;
import java.math.BigDecimal;
import java.time.Clock;
import java.time.Instant;
import java.time.LocalDate;
import java.util.Locale;
import java.util.UUID;
import java.util.regex.Pattern;
import org.springframework.data.domain.Page;
import org.springframework.data.domain.PageRequest;
import org.springframework.data.domain.Sort;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/**
 * Codes promo : gestion par l'ADMIN, aperçu pour le client (/check), validation et application par
 * reservation-service (/internal/promo-codes/validate et /apply).
 *
 * <p>Ordre des contrôles (cahier des charges, section 7), le premier qui échoue décide :
 * <ol>
 *   <li>code inconnu -&gt; 404 code</li>
 *   <li>code inactif, pas encore valide ou expiré -&gt; 422 code</li>
 *   <li>bon personnel d'un autre client -&gt; 403 code</li>
 *   <li>client sans compte de fidélité actif, ou inactif dans user-service -&gt; 422 userId</li>
 *   <li>niveau du client inférieur à minTier -&gt; 403 code</li>
 *   <li>montant inférieur à minAmount -&gt; 422 amount</li>
 *   <li>code épuisé (usedCount = maxUses) -&gt; 409 code</li>
 *   <li>déjà utilisé par ce client (maxUsesPerUser atteint) -&gt; 409 code</li>
 * </ol>
 */
@Service
public class PromoCodeService {

    private static final Pattern CODE_FORMAT = Pattern.compile("^[A-Z0-9-]{4,20}$");
    private static final BigDecimal MAX_PERCENT = BigDecimal.valueOf(50);

    private final PromoCodeRepository codes;
    private final PromoUsageRepository usages;
    private final LoyaltyAccountRepository accounts;
    private final RemoteServices remote;
    private final Clock clock;

    public PromoCodeService(PromoCodeRepository codes, PromoUsageRepository usages, LoyaltyAccountRepository accounts,
                            RemoteServices remote, Clock clock) {
        this.codes = codes;
        this.usages = usages;
        this.accounts = accounts;
        this.remote = remote;
        this.clock = clock;
    }

    public static String normalize(String code) {
        return code == null ? null : code.trim().toUpperCase(Locale.ROOT);
    }

    // ---------------------------------------------------------------- gestion (ADMIN, AGENT en lecture)

    @Transactional(readOnly = true)
    public Page<PromoCode> list(Boolean active, int page, int size) {
        return codes.findPublicCodes(active, PageRequest.of(page, size, Sort.by(Sort.Direction.DESC, "createdAt")));
    }

    @Transactional(readOnly = true)
    public PromoCode get(UUID id) {
        return codes.findById(id).orElseThrow(() -> ApiException.notFound("Code promo introuvable : " + id));
    }

    @Transactional
    public PromoCode create(PromoCodeRequest request) {
        String code = normalize(request.code());
        if (!CODE_FORMAT.matcher(code).matches()) {
            throw ApiException.unprocessable("Le code doit contenir de 4 à 20 caractères parmi A-Z, 0-9 et tiret", "code");
        }
        if (codes.existsByCode(code)) {
            throw ApiException.conflict("Le code promo " + code + " existe déjà", "code");
        }
        int maxPerUser = request.maxUsesPerUser() == null ? 1 : request.maxUsesPerUser();
        checkRules(request.discountType(), request.discountValue(), request.minAmount(), request.validFrom(),
                request.validUntil(), request.maxUses(), maxPerUser, 0);
        return codes.save(new PromoCode(code, request.discountType(), request.discountValue(), request.minAmount(),
                request.minTier(), request.validFrom(), request.validUntil(), request.maxUses(), maxPerUser, null,
                Instant.now(clock)));
    }

    @Transactional
    public PromoCode update(UUID id, PromoCodeUpdateRequest request) {
        PromoCode promo = get(id);
        int maxPerUser = request.maxUsesPerUser() == null ? 1 : request.maxUsesPerUser();
        checkRules(request.discountType(), request.discountValue(), request.minAmount(), request.validFrom(),
                request.validUntil(), request.maxUses(), maxPerUser, promo.getUsedCount());
        promo.update(request.discountType(), request.discountValue(), request.minAmount(), request.minTier(),
                request.validFrom(), request.validUntil(), request.maxUses(), maxPerUser,
                request.active() == null || request.active());
        return promo;
    }

    /** Suppression seulement si le code n'a jamais servi ; sinon 409 : il faut le désactiver. */
    @Transactional
    public void delete(UUID id) {
        PromoCode promo = get(id);
        if (promo.getUsedCount() > 0 || usages.existsByPromoCodeId(id)) {
            throw ApiException.conflict("Ce code a déjà été utilisé (" + promo.getUsedCount()
                    + " fois) : désactivez-le au lieu de le supprimer", "id");
        }
        codes.delete(promo);
    }

    private static void checkRules(DiscountType type, BigDecimal value, BigDecimal minAmount, LocalDate from,
                                   LocalDate until, Integer maxUses, int maxPerUser, int usedCount) {
        if (value.signum() <= 0) {
            throw ApiException.unprocessable("La réduction doit être supérieure à 0", "discountValue");
        }
        if (type == DiscountType.PERCENT && (value.compareTo(BigDecimal.ONE) < 0 || value.compareTo(MAX_PERCENT) > 0)) {
            throw ApiException.unprocessable("Un pourcentage doit être compris entre 1 et 50", "discountValue");
        }
        if (value.scale() > 3 && value.stripTrailingZeros().scale() > 3) {
            throw ApiException.unprocessable("Au plus 3 décimales", "discountValue");
        }
        if (minAmount != null && minAmount.signum() < 0) {
            throw ApiException.unprocessable("Le montant minimum ne peut pas être négatif", "minAmount");
        }
        if (!until.isAfter(from)) {
            throw ApiException.unprocessable("validUntil doit être après validFrom", "validUntil");
        }
        if (maxUses != null && maxUses <= 0) {
            throw ApiException.unprocessable("maxUses doit être supérieur à 0 (ou absent : illimité)", "maxUses");
        }
        if (maxUses != null && maxUses < usedCount) {
            throw ApiException.unprocessable("maxUses ne peut pas être inférieur au nombre d'utilisations ("
                    + usedCount + ")", "maxUses");
        }
        if (maxPerUser <= 0) {
            throw ApiException.unprocessable("maxUsesPerUser doit être supérieur à 0", "maxUsesPerUser");
        }
    }

    // ---------------------------------------------------------------- utilisation

    /** Aperçu pour Angular (client connecté) : ne consomme rien et n'appelle pas user-service. */
    @Transactional(readOnly = true)
    public Evaluation check(String code, UUID userId, BigDecimal amount) {
        return evaluate(find(code), userId, amount, false);
    }

    /** /internal/promo-codes/validate : mêmes contrôles + utilisateur actif dans user-service ; ne consomme rien. */
    @Transactional(readOnly = true)
    public Evaluation validate(String code, UUID userId, BigDecimal amount) {
        return evaluate(find(code), userId, amount, true);
    }

    public record Applied(PromoUsage usage, PromoCode code, boolean created) {
    }

    /**
     * /internal/promo-codes/apply : revalide puis enregistre l'utilisation (usedCount + 1).
     * Idempotent : même reservationId -&gt; la même utilisation, sans rien compter deux fois.
     */
    @Transactional
    public Applied apply(String code, UUID userId, UUID reservationId, BigDecimal amount) {
        String normalized = normalize(code);
        var existing = usages.findByReservationId(reservationId);
        if (existing.isPresent()) {
            PromoUsage usage = existing.get();
            PromoCode used = codes.findById(usage.getPromoCodeId()).orElseThrow();
            if (!used.getCode().equals(normalized) || !usage.getUserId().equals(userId)) {
                throw ApiException.conflict("La réservation " + reservationId + " a déjà utilisé le code "
                        + used.getCode(), "reservationId");
            }
            return new Applied(usage, used, false);
        }
        // Verrou sur la ligne du code : deux réservations simultanées ne dépassent pas maxUses.
        PromoCode promo = codes.findByCodeForUpdate(normalized)
                .orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND, "Code promo introuvable : " + normalized, "code"));
        Evaluation evaluation = evaluate(promo, userId, amount, true);
        promo.markUsed();
        PromoUsage usage = usages.save(new PromoUsage(promo.getId(), userId, reservationId, evaluation.discount(),
                Instant.now(clock)));
        return new Applied(usage, promo, true);
    }

    public record Evaluation(PromoCode code, Discount discount) {
    }

    private PromoCode find(String code) {
        String normalized = normalize(code);
        return codes.findByCode(normalized)
                .orElseThrow(() -> new ApiException(HttpStatus.NOT_FOUND, "Code promo introuvable : " + normalized, "code"));
    }

    private Evaluation evaluate(PromoCode promo, UUID userId, BigDecimal amount, boolean checkUserService) {
        LocalDate today = LocalDate.now(clock);
        if (!promo.isActive()) {
            throw ApiException.unprocessable("Le code promo " + promo.getCode() + " est désactivé", "code");
        }
        if (today.isBefore(promo.getValidFrom())) {
            throw ApiException.unprocessable("Le code promo " + promo.getCode() + " n'est valable qu'à partir du "
                    + promo.getValidFrom(), "code");
        }
        if (today.isAfter(promo.getValidUntil())) {
            throw ApiException.unprocessable("Le code promo " + promo.getCode() + " a expiré le " + promo.getValidUntil(),
                    "code");
        }
        if (promo.isPersonal() && !promo.getOwnerUserId().equals(userId)) {
            throw new ApiException(HttpStatus.FORBIDDEN, "Ce bon appartient à un autre client", "code");
        }
        LoyaltyAccount account = accounts.findById(userId).filter(LoyaltyAccount::isActive)
                .orElseThrow(() -> ApiException.unprocessable("Le client n'a pas de compte de fidélité actif", "userId"));
        if (checkUserService) {
            boolean active = remote.findUser(userId).map(u -> Boolean.TRUE.equals(u.active())).orElse(false);
            if (!active) {
                throw ApiException.unprocessable("Utilisateur introuvable ou inactif dans user-service", "userId");
            }
        }
        Tier minTier = promo.getMinTier();
        if (!account.getTier().atLeast(minTier)) {
            throw new ApiException(HttpStatus.FORBIDDEN, "Ce code est réservé au niveau " + minTier
                    + " (niveau du client : " + account.getTier() + ")", "code");
        }
        if (promo.getMinAmount() != null && amount.compareTo(promo.getMinAmount()) < 0) {
            throw ApiException.unprocessable("Ce code demande un montant d'au moins "
                    + promo.getMinAmount().setScale(3) + " TND", "amount");
        }
        if (promo.isExhausted()) {
            throw ApiException.conflict("Le code promo " + promo.getCode()
                    + " a atteint son nombre maximal d'utilisations", "code");
        }
        if (usages.countByPromoCodeIdAndUserId(promo.getId(), userId) >= promo.getMaxUsesPerUser()) {
            throw ApiException.conflict("Ce client a déjà utilisé le code " + promo.getCode(), "code");
        }
        return new Evaluation(promo, Discount.compute(promo.getDiscountType(), promo.getDiscountValue(), amount));
    }
}
