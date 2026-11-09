import { AbstractControl, FormGroup } from '@angular/forms';
import { ApiError, errorMessage } from '../../core/api';

/**
 * Place les erreurs "details" renvoyées par le service (409, 422...) sur les champs du formulaire
 * et renvoie le message général à afficher.
 */
export function applyServerErrors(form: FormGroup, err: unknown): string {
  const details = (err as { error?: Partial<ApiError> })?.error?.details ?? [];
  for (const d of details) {
    const control = form.get(d.field);
    if (control) {
      control.setErrors({ server: d.message });
      control.markAsTouched();
    }
  }
  return errorMessage(err);
}

/** Message à afficher sous un champ, ou null. */
export function fieldMessage(control: AbstractControl): string | null {
  const errors = control.errors;
  if (!errors || !(control.touched || control.dirty)) return null;
  if (errors['server']) return errors['server'] as string;
  if (errors['required']) return 'Obligatoire';
  if (errors['maxlength']) return 'Trop long';
  if (errors['pattern']) return 'Format invalide';
  return 'Valeur invalide';
}

/** Statut HTTP d'une erreur (0 si inconnu). */
export function httpStatus(err: unknown): number {
  return (err as { status?: number })?.status ?? 0;
}
