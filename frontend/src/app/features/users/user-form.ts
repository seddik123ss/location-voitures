import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { applyServerErrors, fieldMessage } from './form-errors';
import { UsersApi } from './users.api';
import { EMAIL_PATTERN, PHONE_PATTERN, ROLES, UUID_PATTERN, UserRole } from './users.model';

/** Création (/users/admin/new) et modification (/users/admin/:id/edit) d'un utilisateur. Réservé à l'ADMIN. */
@Component({
  selector: 'app-user-form',
  imports: [ReactiveFormsModule, RouterLink],
  styleUrl: './users.scss',
  template: `
    <div class="page">
      <div class="head">
        <h1>{{ id ? "Modifier l'utilisateur" : 'Nouvel utilisateur' }}</h1>
        <a class="btn" [routerLink]="id ? ['/users/admin', id] : ['/users/admin']">Annuler</a>
      </div>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      <form class="card" [formGroup]="form" (ngSubmit)="save()">
        <div class="grid">
          @if (!id) {
            <label>Identifiant Keycloak (sub)
              <input formControlName="id" placeholder="Keycloak > Users > l'utilisateur > ID" />
              <span class="err">{{ msg(form.controls.id) }}</span>
            </label>
          }
          <label>Prénom <input formControlName="firstname" /><span class="err">{{ msg(form.controls.firstname) }}</span></label>
          <label>Nom <input formControlName="lastname" /><span class="err">{{ msg(form.controls.lastname) }}</span></label>
          <label>Email <input type="email" formControlName="email" /><span class="err">{{ msg(form.controls.email) }}</span></label>
          <label>Téléphone <input formControlName="phone" placeholder="+21620111111" /><span class="err">{{ msg(form.controls.phone) }}</span></label>
          <label>Rôle
            <select formControlName="role">
              @for (r of roles; track r) { <option [value]="r">{{ r }}</option> }
            </select>
          </label>
          <label class="check"><input type="checkbox" formControlName="active" /> Compte actif</label>
        </div>
        @if (!id) {
          <p class="muted">L'identifiant doit être celui de l'utilisateur dans Keycloak (realm location-voitures), pour que son token corresponde à ce profil.</p>
        }
        <div class="actions"><button type="submit" class="primary" [disabled]="saving()">Enregistrer</button></div>
      </form>
    </div>
  `,
})
export class UserForm implements OnInit {
  private readonly api = inject(UsersApi);
  private readonly router = inject(Router);
  protected readonly id = inject(ActivatedRoute).snapshot.paramMap.get('id');
  protected readonly msg = fieldMessage;
  protected readonly roles = ROLES;
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group({
    id: ['', [Validators.required, Validators.pattern(UUID_PATTERN)]],
    firstname: ['', [Validators.required, Validators.maxLength(100)]],
    lastname: ['', [Validators.required, Validators.maxLength(100)]],
    email: ['', [Validators.required, Validators.pattern(EMAIL_PATTERN)]],
    phone: ['', Validators.pattern(PHONE_PATTERN)],
    role: 'CLIENT' as UserRole,
    active: true,
  });

  ngOnInit(): void {
    if (!this.id) return;
    this.form.controls.id.disable();
    this.api.get(this.id).subscribe({
      next: (u) => this.form.patchValue({ ...u, phone: u.phone ?? '' }),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const { id: newId, ...v } = this.form.getRawValue();
    const body = { ...v, phone: v.phone || null };
    const call = this.id ? this.api.update(this.id, body) : this.api.create({ id: newId, ...body });
    this.saving.set(true);
    call.subscribe({
      next: (u) => this.router.navigate(['/users/admin', u.id]),
      error: (e) => {
        this.saving.set(false);
        this.error.set(applyServerErrors(this.form, e));
      },
    });
  }
}
