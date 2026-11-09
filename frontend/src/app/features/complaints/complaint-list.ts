import { DatePipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Page } from '../../core/api';
import { hasRole } from '../../core/keycloak';
import { complaintError, ComplaintsApi } from './complaints.api';
import {
  CATEGORIES,
  CATEGORY_LABELS,
  ComplaintCategory,
  ComplaintPriority,
  ComplaintStatus,
  ComplaintSummary,
  PRIORITIES,
  PRIORITY_LABELS,
  STATUS_LABELS,
  STATUSES,
  UUID_PATTERN,
} from './complaints.models';

/**
 * CLIENT : « Mes réclamations » (l'API ne renvoie que les siennes), filtres statut et catégorie.
 * AGENT / ADMIN : toutes les réclamations, filtres statut, catégorie, priorité et client.
 */
@Component({
  selector: 'app-complaint-list',
  imports: [FormsModule, RouterLink, DatePipe],
  templateUrl: './complaint-list.html',
  styleUrls: ['./complaints.scss', './complaint-list.scss'],
})
export class ComplaintList {
  private readonly api = inject(ComplaintsApi);

  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly isClient = hasRole('CLIENT');
  protected readonly statuses = STATUSES;
  protected readonly categories = CATEGORIES;
  protected readonly priorities = PRIORITIES;
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly categoryLabels = CATEGORY_LABELS;
  protected readonly priorityLabels = PRIORITY_LABELS;

  protected readonly status = signal<ComplaintStatus | ''>('');
  protected readonly category = signal<ComplaintCategory | ''>('');
  protected readonly priority = signal<ComplaintPriority | ''>('');
  protected readonly userId = signal('');
  protected readonly page = signal(0);
  protected readonly size = 10;

  protected readonly result = signal<Page<ComplaintSummary> | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal('');
  /** Message laissé par la page précédente (ex. après une suppression). */
  protected readonly notice = signal((history.state as { flash?: string } | null)?.flash ?? '');

  protected readonly userIdInvalid = computed(() => {
    const value = this.userId().trim();
    return value !== '' && !UUID_PATTERN.test(value);
  });
  protected readonly pageCount = computed(() => {
    const r = this.result();
    return r ? Math.max(1, Math.ceil(r.total / r.size)) : 1;
  });

  constructor() {
    this.load();
  }

  protected search(): void {
    this.page.set(0);
    this.load();
  }

  protected reset(): void {
    this.status.set('');
    this.category.set('');
    this.priority.set('');
    this.userId.set('');
    this.search();
  }

  protected goTo(page: number): void {
    this.page.set(page);
    this.load();
  }

  private load(): void {
    if (this.userIdInvalid()) {
      return;
    }
    this.loading.set(true);
    this.error.set('');
    this.api
      .list({
        page: this.page(),
        size: this.size,
        status: this.status(),
        category: this.category(),
        priority: this.staff ? this.priority() : '',
        userId: this.staff ? this.userId() : '',
      })
      .subscribe({
        next: (page) => {
          this.result.set(page);
          this.loading.set(false);
        },
        error: (err) => {
          this.error.set(complaintError(err));
          this.result.set(null);
          this.loading.set(false);
        },
      });
  }
}
