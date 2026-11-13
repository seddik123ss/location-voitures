import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { applyServerErrors, fieldMessage } from './form-errors';
import { LoyaltyApi } from './loyalty.api';
import { CODE_PATTERN, DISCOUNT_TYPES, DiscountType, PromoCodeRequest, TIERS, Tier } from './loyalty.model';

/**
 * Création (/loyalty/admin/promo-codes/new) et modification (/loyalty/admin/promo-codes/:id/edit) d'un code. ADMIN.
 * Validation locale, puis les erreurs 409 et 422 du serveur s'affichent sous le champ concerné (details[].field).
 */
@Component({
  selector: 'app-promo-code-form',
  imports: [ReactiveFormsModule, RouterLink],
  styleUrl: './loyalty.scss',
  template: `
    <div class="page">
      <div class="head">
        <h1>{{ id ? 'Modifier le code ' + loadedCode() : 'Nouveau code promo' }}</h1>
        <a class="btn" routerLink="/loyalty/admin/promo-codes">Annuler</a>
      </div>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      <form class="card" [formGroup]="form" (ngSubmit)="save()">
        <div class="grid">
          @if (!id) {
            <label>Code
              <input formControlName="code" placeholder="ex. PRINTEMPS20" />
              <span class="err">{{ msg(form.controls.code) }}</span>
            </label>
          }
          <label>Type
            <select formControlName="discountType">
              @for (t of types; track t) { <option [value]="t">{{ t === 'PERCENT' ? 'Pourcentage' : 'Montant (TND)' }}</option> }
            </select>
          </label>
          <label>Réduction {{ form.controls.discountType.value === 'PERCENT' ? '(1 à 50 %)' : '(TND)' }}
            <input type="number" step="0.001" formControlName="discountValue" />
            <span class="err">{{ msg(form.controls.discountValue) }}</span>
          </label>
          <label>Montant minimum (TND, facultatif)
            <input type="number" step="0.001" formControlName="minAmount" />
            <span class="err">{{ msg(form.controls.minAmount) }}</span>
          </label>
          <label>Niveau minimum
            <select formControlName="minTier">
              <option value="">Aucun</option>
              @for (t of tiers; track t) { <option [value]="t">{{ t }}</option> }
            </select>
          </label>
          <label>Valable du <input type="date" formControlName="validFrom" /><span class="err">{{ msg(form.controls.validFrom) }}</span></label>
          <label>au (inclus) <input type="date" formControlName="validUntil" /><span class="err">{{ msg(form.controls.validUntil) }}</span></label>
          <label>Utilisations max. (vide = illimité)
            <input type="number" min="1" formControlName="maxUses" />
            <span class="err">{{ msg(form.controls.maxUses) }}</span>
          </label>
          <label>Utilisations par client
            <input type="number" min="1" formControlName="maxUsesPerUser" />
            <span class="err">{{ msg(form.controls.maxUsesPerUser) }}</span>
          </label>
          @if (id) { <label class="check"><input type="checkbox" formControlName="active" /> Code actif</label> }
        </div>
        <div class="actions">
          <button type="submit" class="primary" [disabled]="busy()">{{ id ? 'Enregistrer' : 'Créer' }}</button>
        </div>
      </form>
    </div>
  `,
})
export class PromoCodeForm implements OnInit {
  private readonly api = inject(LoyaltyApi);
  private readonly router = inject(Router);
  protected readonly id = inject(ActivatedRoute).snapshot.paramMap.get('id');
  protected readonly types = DISCOUNT_TYPES;
  protected readonly tiers = TIERS;
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly loadedCode = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group({
    code: ['', [Validators.required, Validators.pattern(CODE_PATTERN)]],
    discountType: ['PERCENT' as DiscountType, Validators.required],
    discountValue: [10 as number | null, [Validators.required, Validators.min(0.001)]],
    minAmount: [null as number | null, Validators.min(0)],
    minTier: ['' as Tier | ''],
    validFrom: ['', Validators.required],
    validUntil: ['', Validators.required],
    maxUses: [null as number | null, Validators.min(1)],
    maxUsesPerUser: [1, [Validators.required, Validators.min(1)]],
    active: [true],
  });

  ngOnInit(): void {
    if (!this.id) return;
    this.form.controls.code.disable();
    this.api.promoCode(this.id).subscribe({
      next: (c) => {
        this.loadedCode.set(c.code);
        this.form.patchValue({ ...c, minTier: c.minTier ?? '' });
      },
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    const body: PromoCodeRequest = {
      discountType: v.discountType,
      discountValue: Number(v.discountValue),
      minAmount: v.minAmount === null || (v.minAmount as unknown) === '' ? null : Number(v.minAmount),
      minTier: v.minTier || null,
      validFrom: v.validFrom,
      validUntil: v.validUntil,
      maxUses: v.maxUses === null || (v.maxUses as unknown) === '' ? null : Number(v.maxUses),
      maxUsesPerUser: Number(v.maxUsesPerUser),
    };
    this.busy.set(true);
    this.error.set('');
    const call = this.id
      ? this.api.updatePromoCode(this.id, { ...body, active: v.active })
      : this.api.createPromoCode({ ...body, code: v.code.trim().toUpperCase() });
    call.subscribe({
      next: () => this.router.navigate(['/loyalty/admin/promo-codes']),
      error: (e) => {
        this.busy.set(false);
        this.error.set(applyServerErrors(this.form, e));
      },
    });
  }

  protected msg = fieldMessage;
}
