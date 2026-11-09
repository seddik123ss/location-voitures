import { NgTemplateOutlet } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { currentUserId, hasRole, keycloak } from '../../core/keycloak';
import { AddressSection } from './address-section';
import { applyServerErrors, fieldMessage, httpStatus } from './form-errors';
import { LicenseSection } from './license-section';
import { ReservationSection } from './reservation-section';
import { UsersApi } from './users.api';
import { EMAIL_PATTERN, PHONE_PATTERN, UserProfile } from './users.model';

/**
 * Page « Mon profil » : informations personnelles, adresses, permis et historique des réservations.
 * Si le profil n'existe pas encore dans user-service (404), propose de le créer à partir du compte Keycloak.
 */
@Component({
  selector: 'app-my-profile',
  imports: [ReactiveFormsModule, RouterLink, NgTemplateOutlet, AddressSection, LicenseSection, ReservationSection],
  templateUrl: './my-profile.html',
  styleUrl: './users.scss',
})
export class MyProfile implements OnInit {
  private readonly api = inject(UsersApi);
  protected readonly msg = fieldMessage;
  protected readonly isStaff = hasRole('ADMIN', 'AGENT');

  protected readonly profile = signal<UserProfile | null>(null);
  protected readonly loading = signal(true);
  protected readonly missing = signal(false);
  protected readonly editing = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly success = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group({
    firstname: ['', [Validators.required, Validators.maxLength(100)]],
    lastname: ['', [Validators.required, Validators.maxLength(100)]],
    email: ['', [Validators.required, Validators.pattern(EMAIL_PATTERN)]],
    phone: ['', Validators.pattern(PHONE_PATTERN)],
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.api.me().subscribe({
      next: (p) => {
        this.profile.set(p);
        this.missing.set(false);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        if (httpStatus(e) === 404) {
          this.missing.set(true);
          this.prefillFromKeycloak();
        } else {
          this.error.set(errorMessage(e));
        }
      },
    });
  }

  protected startEdit(p: UserProfile): void {
    this.form.reset({ firstname: p.firstname, lastname: p.lastname, email: p.email, phone: p.phone ?? '' });
    this.success.set('');
    this.error.set('');
    this.editing.set(true);
  }

  /** Enregistre les informations (le rôle et le statut ne changent pas : réservé à l'ADMIN). */
  protected save(p: UserProfile): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.api.update(p.id, { ...v, phone: v.phone || null, role: p.role, active: p.active }).subscribe({
      next: (updated) => {
        this.profile.set(updated);
        this.saving.set(false);
        this.editing.set(false);
        this.success.set('Profil enregistré.');
      },
      error: (e) => {
        this.saving.set(false);
        this.error.set(applyServerErrors(this.form, e));
      },
    });
  }

  /** Premier accès : crée le profil CLIENT avec l'identifiant Keycloak (sub). */
  protected create(): void {
    const id = currentUserId();
    if (!id) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.api.create({ id, ...v, phone: v.phone || null, role: 'CLIENT', active: true }).subscribe({
      next: (p) => {
        this.profile.set(p);
        this.missing.set(false);
        this.saving.set(false);
        this.success.set('Profil créé.');
      },
      error: (e) => {
        this.saving.set(false);
        this.error.set(applyServerErrors(this.form, e));
      },
    });
  }

  private prefillFromKeycloak(): void {
    const t = keycloak.tokenParsed as { given_name?: string; family_name?: string; email?: string } | undefined;
    this.form.reset({ firstname: t?.given_name ?? '', lastname: t?.family_name ?? '', email: t?.email ?? '', phone: '' });
  }
}
