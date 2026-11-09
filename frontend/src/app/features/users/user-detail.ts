import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { AddressSection } from './address-section';
import { LicenseSection } from './license-section';
import { ReservationSection } from './reservation-section';
import { UsersApi } from './users.api';
import { UserProfile } from './users.model';

/** Fiche d'un utilisateur pour ADMIN et AGENT : profil, permis (vérification), adresses, réservations. */
@Component({
  selector: 'app-user-detail',
  imports: [RouterLink, DatePipe, AddressSection, LicenseSection, ReservationSection],
  styleUrl: './users.scss',
  template: `
    <div class="page">
      <div class="head">
        <h1>{{ user() ? user()!.firstname + ' ' + user()!.lastname : 'Utilisateur' }}</h1>
        <div class="row">
          <a class="btn" routerLink="/users/admin">Retour à la liste</a>
          @if (isAdmin && user(); as u) {
            <a class="btn" [routerLink]="['/users/admin', u.id, 'edit']">Modifier</a>
            <button type="button" class="danger" (click)="remove(u)">Supprimer</button>
          }
        </div>
      </div>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (user(); as u) {
        <section class="card">
          <div class="head">
            <h2>Profil</h2>
            <div class="row">
              <span class="badge">{{ u.role }}</span>
              @if (u.active) { <span class="badge green">Actif</span> } @else { <span class="badge red">Désactivé</span> }
            </div>
          </div>
          <dl>
            <dt>Identifiant</dt><dd><code>{{ u.id }}</code></dd>
            <dt>Email</dt><dd>{{ u.email }}</dd>
            <dt>Téléphone</dt><dd>{{ u.phone ?? '—' }}</dd>
            <dt>Créé le</dt><dd>{{ u.createdAt | date: 'dd/MM/yyyy HH:mm' }}</dd>
            <dt>Modifié le</dt><dd>{{ u.updatedAt | date: 'dd/MM/yyyy HH:mm' }}</dd>
          </dl>
        </section>
        <app-license-section [userId]="u.id" [license]="u.license" [canEdit]="isAdmin" [canVerify]="true" (changed)="load(u.id)" />
        <app-address-section [userId]="u.id" [addresses]="u.addresses" [canEdit]="isAdmin" (changed)="load(u.id)" />
        <app-reservation-section [userId]="u.id" />
      } @else if (!error()) {
        <p class="muted">Chargement...</p>
      }
    </div>
  `,
})
export class UserDetail implements OnInit {
  private readonly api = inject(UsersApi);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  protected readonly isAdmin = hasRole('ADMIN');
  protected readonly user = signal<UserProfile | null>(null);
  protected readonly error = signal('');

  ngOnInit(): void {
    this.load(this.route.snapshot.paramMap.get('id')!);
  }

  protected load(id: string): void {
    this.api.get(id).subscribe({
      next: (u) => this.user.set(u),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected remove(u: UserProfile): void {
    if (!confirm(`Supprimer ${u.firstname} ${u.lastname} ? Ses adresses et son permis seront aussi supprimés.`)) return;
    this.api.delete(u.id).subscribe({
      next: () => this.router.navigate(['/users/admin']),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }
}
