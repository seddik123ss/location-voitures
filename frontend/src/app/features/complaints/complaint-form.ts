import { DatePipe } from '@angular/common';
import { Component, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { currentUserId } from '../../core/keycloak';
import { complaintError, ComplaintsApi } from './complaints.api';
import {
  CATEGORIES,
  CATEGORY_LABELS,
  ComplaintCategory,
  ComplaintPriority,
  PRIORITIES,
  PRIORITY_LABELS,
  ReservationOption,
  UUID_PATTERN,
} from './complaints.models';

type Target = 'reservation' | 'contract';
type ReservationsState = 'loading' | 'list' | 'empty' | 'unavailable';

/**
 * Nouvelle réclamation (route new, CLIENT) ou modification (route :id/edit, propriétaire, OPEN seulement).
 * Sujet, description et catégorie sont modifiables ; la réservation, le contrat et la priorité sont fixés à la création.
 */
@Component({
  selector: 'app-complaint-form',
  imports: [ReactiveFormsModule, RouterLink, DatePipe],
  templateUrl: './complaint-form.html',
  styleUrls: ['./complaints.scss', './complaint-form.scss'],
})
export class ComplaintForm implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(ComplaintsApi);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder).nonNullable;

  protected readonly categories = CATEGORIES;
  protected readonly priorities = PRIORITIES;
  protected readonly categoryLabels = CATEGORY_LABELS;
  protected readonly priorityLabels = PRIORITY_LABELS;

  protected readonly form = this.fb.group({
    target: this.fb.control<Target>('reservation'),
    reservationId: this.fb.control(''),
    contractId: this.fb.control(''),
    category: this.fb.control<ComplaintCategory | ''>('', Validators.required),
    priority: this.fb.control<ComplaintPriority>('MEDIUM'),
    subject: this.fb.control('', [Validators.required, Validators.maxLength(150), Validators.pattern(/\S/)]),
    description: this.fb.control('', [Validators.required, Validators.maxLength(5000), Validators.pattern(/\S/)]),
  });

  protected editId = '';
  protected readonly reservations = signal<ReservationOption[]>([]);
  protected readonly reservationsState = signal<ReservationsState>('loading');
  protected readonly loadError = signal('');
  protected readonly error = signal('');
  protected readonly saving = signal(false);
  protected readonly submitted = signal(false);

  ngOnInit(): void {
    this.editId = this.route.snapshot.paramMap.get('id') ?? '';
    if (this.editId) {
      this.loadForEdit();
    } else {
      this.loadReservations();
    }
  }

  protected get isEdit(): boolean {
    return this.editId !== '';
  }

  protected setTarget(target: Target): void {
    this.form.controls.target.setValue(target);
  }

  protected showError(name: 'reservationId' | 'contractId' | 'category' | 'subject' | 'description'): boolean {
    const control = this.form.controls[name];
    return control.invalid && (control.touched || this.submitted());
  }

  /** Une réservation OU un contrat, en UUID. */
  protected targetError(): string {
    if (this.isEdit) {
      return '';
    }
    const { target, reservationId, contractId } = this.form.getRawValue();
    const value = (target === 'reservation' ? reservationId : contractId).trim();
    if (value === '') {
      return target === 'reservation' ? 'Choisissez une réservation.' : "Saisissez l'identifiant du contrat.";
    }
    return UUID_PATTERN.test(value) ? '' : 'Identifiant au format UUID attendu (ex. 33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8).';
  }

  protected submit(): void {
    this.submitted.set(true);
    this.error.set('');
    if (this.form.invalid || this.targetError()) {
      return;
    }
    const v = this.form.getRawValue();
    const common = { category: v.category as ComplaintCategory, subject: v.subject.trim(), description: v.description.trim() };
    const request = this.isEdit
      ? this.api.update(this.editId, common)
      : this.api.create({
          ...common,
          priority: v.priority,
          reservationId: v.target === 'reservation' ? v.reservationId.trim() : null,
          contractId: v.target === 'contract' ? v.contractId.trim() : null,
        });

    this.saving.set(true);
    request.subscribe({
      next: (complaint) => this.router.navigate(['/complaints', complaint.id], { state: { flash: this.isEdit ? 'Réclamation modifiée.' : 'Réclamation créée.' } }),
      error: (err) => {
        this.error.set(complaintError(err));
        this.saving.set(false);
      },
    });
  }

  private loadReservations(): void {
    this.api.myReservations().subscribe({
      next: (items) => {
        this.reservations.set(items);
        this.reservationsState.set(items.length ? 'list' : 'empty');
      },
      error: () => this.reservationsState.set('unavailable'),
    });
  }

  private loadForEdit(): void {
    this.api.get(this.editId).subscribe({
      next: (c) => {
        if (c.userId !== currentUserId()) {
          this.loadError.set('Seul le client auteur de la réclamation peut la modifier.');
        } else if (c.status !== 'OPEN') {
          this.loadError.set('Seule une réclamation ouverte peut être modifiée : celle-ci est déjà prise en charge ou clôturée.');
        }
        this.form.patchValue({ category: c.category, subject: c.subject, description: c.description });
      },
      error: (err) => this.loadError.set(complaintError(err, 'load')),
    });
  }
}
