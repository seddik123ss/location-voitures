import { Component, inject, input, output, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { errorMessage } from '../../core/api';
import { applyServerErrors, fieldMessage } from './form-errors';
import { UsersApi } from './users.api';
import { Address, ZIP_PATTERN } from './users.model';

/** Liste des adresses d'un utilisateur, avec ajout / modification / suppression si autorisé. */
@Component({
  selector: 'app-address-section',
  imports: [ReactiveFormsModule],
  styleUrl: './users.scss',
  template: `
    <section class="card">
      <div class="head">
        <h2>Adresses</h2>
        @if (canEdit() && editing() === null) {
          <button type="button" (click)="startNew()">Ajouter une adresse</button>
        }
      </div>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (addresses().length === 0 && editing() !== 'new') {
        <p class="muted">Aucune adresse enregistrée.</p>
      } @else if (addresses().length > 0) {
        <table>
          <thead><tr><th>Rue</th><th>Ville</th><th>Code postal</th>@if (canEdit()) { <th></th> }</tr></thead>
          <tbody>
            @for (a of addresses(); track a.id) {
              <tr>
                <td>{{ a.street }}</td>
                <td>{{ a.city }}</td>
                <td>{{ a.zipCode }}</td>
                @if (canEdit()) {
                  <td class="row">
                    <button type="button" (click)="startEdit(a)">Modifier</button>
                    <button type="button" class="danger" (click)="remove(a)">Supprimer</button>
                  </td>
                }
              </tr>
            }
          </tbody>
        </table>
      }

      @if (editing() !== null) {
        <form [formGroup]="form" (ngSubmit)="save()" class="card">
          <h2>{{ editing() === 'new' ? 'Nouvelle adresse' : "Modifier l'adresse" }}</h2>
          <div class="grid">
            <label>Rue <input formControlName="street" /><span class="err">{{ msg(form.controls.street) }}</span></label>
            <label>Ville <input formControlName="city" /><span class="err">{{ msg(form.controls.city) }}</span></label>
            <label>Code postal (4 chiffres) <input formControlName="zipCode" maxlength="4" /><span class="err">{{ msg(form.controls.zipCode) }}</span></label>
          </div>
          <div class="actions">
            <button type="submit" class="primary" [disabled]="saving()">Enregistrer</button>
            <button type="button" (click)="cancel()">Annuler</button>
          </div>
        </form>
      }
    </section>
  `,
})
export class AddressSection {
  readonly userId = input.required<string>();
  readonly addresses = input.required<Address[]>();
  readonly canEdit = input(false);
  readonly changed = output<void>();

  private readonly api = inject(UsersApi);
  protected readonly msg = fieldMessage;
  protected readonly editing = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group({
    street: ['', [Validators.required, Validators.maxLength(255)]],
    city: ['', [Validators.required, Validators.maxLength(100)]],
    zipCode: ['', [Validators.required, Validators.pattern(ZIP_PATTERN)]],
  });

  protected startNew(): void {
    this.form.reset();
    this.error.set('');
    this.editing.set('new');
  }

  protected startEdit(a: Address): void {
    this.form.reset({ street: a.street, city: a.city, zipCode: a.zipCode });
    this.error.set('');
    this.editing.set(a.id);
  }

  protected cancel(): void {
    this.editing.set(null);
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const id = this.editing();
    const body = this.form.getRawValue();
    const call = id === 'new'
      ? this.api.addAddress(this.userId(), body)
      : this.api.updateAddress(this.userId(), id!, body);
    this.saving.set(true);
    call.subscribe({
      next: () => {
        this.saving.set(false);
        this.editing.set(null);
        this.changed.emit();
      },
      error: (e) => {
        this.saving.set(false);
        this.error.set(applyServerErrors(this.form, e));
      },
    });
  }

  protected remove(a: Address): void {
    if (!confirm(`Supprimer l'adresse « ${a.street}, ${a.city} » ?`)) return;
    this.api.deleteAddress(this.userId(), a.id).subscribe({
      next: () => this.changed.emit(),
      error: (e) => this.error.set(errorMessage(e)),
    });
  }
}
