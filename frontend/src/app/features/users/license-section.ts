import { DatePipe } from '@angular/common';
import { Component, inject, input, output, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { errorMessage } from '../../core/api';
import { applyServerErrors, fieldMessage } from './form-errors';
import { UsersApi } from './users.api';
import { LICENSE_CATEGORIES, License } from './users.model';

/**
 * Permis de conduire d'un utilisateur : affichage, ajout / modification / suppression (propriétaire ou ADMIN)
 * et vérification (AGENT ou ADMIN).
 */
@Component({
  selector: 'app-license-section',
  imports: [ReactiveFormsModule, DatePipe],
  styleUrl: './users.scss',
  template: `
    <section class="card">
      <div class="head">
        <h2>Permis de conduire</h2>
        @if (license(); as l) {
          <div class="row">
            @if (l.expired) { <span class="badge red">Expiré</span> }
            @if (l.verified) { <span class="badge green">Vérifié</span> } @else { <span class="badge amber">Non vérifié</span> }
          </div>
        }
      </div>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (!editing()) {
        @if (license(); as l) {
          <dl>
            <dt>Numéro</dt><dd>{{ l.number }}</dd>
            <dt>Catégorie</dt><dd>{{ l.category }}</dd>
            <dt>Délivré le</dt><dd>{{ l.issueDate | date: 'dd/MM/yyyy' }}</dd>
            <dt>Expire le</dt><dd>{{ l.expiryDate | date: 'dd/MM/yyyy' }}</dd>
          </dl>
          <div class="actions">
            @if (canVerify() && !l.verified && !l.expired) {
              <button type="button" class="primary" (click)="verify(l)">Marquer comme vérifié</button>
            }
            @if (canEdit()) {
              <button type="button" (click)="startEdit(l)">Modifier</button>
              <button type="button" class="danger" (click)="remove(l)">Supprimer</button>
            }
          </div>
        } @else {
          <p class="muted">Aucun permis enregistré.</p>
          @if (canEdit()) { <button type="button" class="primary" (click)="startNew()">Ajouter mon permis</button> }
        }
      } @else {
        <form [formGroup]="form" (ngSubmit)="save()">
          <div class="grid">
            <label>Numéro <input formControlName="number" placeholder="TN-2022-000333" /><span class="err">{{ msg(form.controls.number) }}</span></label>
            <label>Catégorie
              <select formControlName="category">
                @for (c of categories; track c) { <option [value]="c">{{ c }}</option> }
              </select>
              <span class="err">{{ msg(form.controls.category) }}</span>
            </label>
            <label>Délivré le <input type="date" formControlName="issueDate" /><span class="err">{{ msg(form.controls.issueDate) }}</span></label>
            <label>Expire le <input type="date" formControlName="expiryDate" /><span class="err">{{ msg(form.controls.expiryDate) }}</span></label>
          </div>
          @if (license()) { <p class="muted">Une modification annule la vérification du permis.</p> }
          <div class="actions">
            <button type="submit" class="primary" [disabled]="saving()">Enregistrer</button>
            <button type="button" (click)="editing.set(false)">Annuler</button>
          </div>
        </form>
      }
    </section>
  `,
})
export class LicenseSection {
  readonly userId = input.required<string>();
  readonly license = input<License | null>(null);
  readonly canEdit = input(false);
  readonly canVerify = input(false);
  readonly changed = output<void>();

  private readonly api = inject(UsersApi);
  protected readonly msg = fieldMessage;
  protected readonly categories = LICENSE_CATEGORIES;
  protected readonly editing = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group({
    number: ['', [Validators.required, Validators.maxLength(30)]],
    category: ['B', Validators.required],
    issueDate: ['', Validators.required],
    expiryDate: ['', Validators.required],
  });

  protected startNew(): void {
    this.form.reset({ number: '', category: 'B', issueDate: '', expiryDate: '' });
    this.error.set('');
    this.editing.set(true);
  }

  protected startEdit(l: License): void {
    this.form.reset({ number: l.number, category: l.category, issueDate: l.issueDate, expiryDate: l.expiryDate });
    this.error.set('');
    this.editing.set(true);
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const body = this.form.getRawValue();
    const current = this.license();
    const call = current
      ? this.api.updateLicense(current.id, body)
      : this.api.createLicense({ ...body, userId: this.userId() });
    this.saving.set(true);
    call.subscribe({
      next: () => {
        this.saving.set(false);
        this.editing.set(false);
        this.changed.emit();
      },
      error: (e) => {
        this.saving.set(false);
        this.error.set(applyServerErrors(this.form, e));
      },
    });
  }

  protected verify(l: License): void {
    this.api.verifyLicense(l.id).subscribe({
      next: () => this.changed.emit(),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }

  protected remove(l: License): void {
    if (!confirm(`Supprimer le permis ${l.number} ?`)) return;
    this.api.deleteLicense(l.id).subscribe({
      next: () => this.changed.emit(),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }
}
