import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Page, errorMessage } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { UsersApi } from './users.api';
import { ROLES, UserRole, UserSummary } from './users.model';

/** Administration des utilisateurs (ADMIN, AGENT en lecture) : filtre par rôle et nom, pagination. */
@Component({
  selector: 'app-user-list',
  imports: [ReactiveFormsModule, RouterLink, DatePipe],
  styleUrl: './users.scss',
  template: `
    <div class="page">
      <div class="head">
        <h1>Utilisateurs</h1>
        <div class="row">
          <a class="btn" routerLink="/users/me">Mon profil</a>
          @if (isAdmin) { <a class="btn primary" routerLink="/users/admin/new">Nouvel utilisateur</a> }
        </div>
      </div>

      <form class="card row" [formGroup]="filters" (ngSubmit)="search()">
        <label>Rôle
          <select formControlName="role">
            <option value="">Tous</option>
            @for (r of roles; track r) { <option [value]="r">{{ r }}</option> }
          </select>
        </label>
        <label>Nom ou prénom <input formControlName="name" placeholder="ex. client" /></label>
        <div class="actions">
          <button type="submit" class="primary">Rechercher</button>
          <button type="button" (click)="reset()">Effacer</button>
        </div>
      </form>

      @if (error()) { <div class="alert">{{ error() }}</div> }

      <section class="card">
        @if (data(); as p) {
          <p class="muted">{{ p.total }} utilisateur(s)</p>
          @if (p.items.length > 0) {
            <table>
              <thead><tr><th>Nom</th><th>Email</th><th>Téléphone</th><th>Rôle</th><th>Statut</th><th>Créé le</th><th></th></tr></thead>
              <tbody>
                @for (u of p.items; track u.id) {
                  <tr>
                    <td><a [routerLink]="['/users/admin', u.id]">{{ u.firstname }} {{ u.lastname }}</a></td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.phone ?? '—' }}</td>
                    <td><span class="badge">{{ u.role }}</span></td>
                    <td>@if (u.active) { <span class="badge green">Actif</span> } @else { <span class="badge red">Désactivé</span> }</td>
                    <td>{{ u.createdAt | date: 'dd/MM/yyyy' }}</td>
                    <td class="row">
                      <a class="btn" [routerLink]="['/users/admin', u.id]">Voir</a>
                      @if (isAdmin) {
                        <a class="btn" [routerLink]="['/users/admin', u.id, 'edit']">Modifier</a>
                        <button type="button" class="danger" (click)="remove(u)">Supprimer</button>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          } @else {
            <p class="muted">Aucun utilisateur ne correspond à la recherche.</p>
          }
          <div class="pager">
            <button type="button" [disabled]="p.page === 0" (click)="load(p.page - 1)">Précédent</button>
            <span class="muted">Page {{ p.page + 1 }} / {{ pages(p) }}</span>
            <button type="button" [disabled]="p.page + 1 >= pages(p)" (click)="load(p.page + 1)">Suivant</button>
          </div>
        } @else {
          <p class="muted">Chargement...</p>
        }
      </section>
    </div>
  `,
})
export class UserList implements OnInit {
  private readonly api = inject(UsersApi);
  private readonly size = 10;
  protected readonly isAdmin = hasRole('ADMIN');
  protected readonly roles = ROLES;
  protected readonly data = signal<Page<UserSummary> | null>(null);
  protected readonly error = signal('');

  protected readonly filters = inject(NonNullableFormBuilder).group({
    role: '' as UserRole | '',
    name: '',
  });

  ngOnInit(): void {
    this.load(0);
  }

  protected search(): void {
    this.load(0);
  }

  protected reset(): void {
    this.filters.reset();
    this.load(0);
  }

  protected load(page: number): void {
    this.error.set('');
    const { role, name } = this.filters.getRawValue();
    this.api.list({ role, name, page, size: this.size }).subscribe({
      next: (p) => this.data.set(p),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected remove(u: UserSummary): void {
    if (!confirm(`Supprimer ${u.firstname} ${u.lastname} ? Ses adresses et son permis seront aussi supprimés.`)) return;
    this.api.delete(u.id).subscribe({
      next: () => this.load(this.data()?.page ?? 0),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected pages(p: Page<UserSummary>): number {
    return Math.max(1, Math.ceil(p.total / p.size));
  }
}
