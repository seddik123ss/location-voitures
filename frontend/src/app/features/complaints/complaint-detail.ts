import { DatePipe, LowerCasePipe } from '@angular/common';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Observable } from 'rxjs';
import { currentUserId, hasRole } from '../../core/keycloak';
import { complaintError, ComplaintsApi } from './complaints.api';
import {
  CATEGORY_LABELS,
  ComplaintDetail as Complaint,
  ComplaintPriority,
  ComplaintStatus,
  isFinal,
  PRIORITIES,
  PRIORITY_LABELS,
  ROLE_LABELS,
  STATUS_LABELS,
} from './complaints.models';

/**
 * Détail d'une réclamation : messages en fil de discussion, historique des statuts.
 * CLIENT auteur : message, modification et suppression tant qu'elle est OPEN.
 * AGENT / ADMIN : changement de statut (transitions de allowedTransitions, avec commentaire), priorité, réponse.
 * Aucune action dans un état final (RESOLVED, REJECTED).
 */
@Component({
  selector: 'app-complaint-detail',
  imports: [FormsModule, RouterLink, DatePipe, LowerCasePipe],
  templateUrl: './complaint-detail.html',
  styleUrls: ['./complaints.scss', './complaint-detail.scss'],
})
export class ComplaintDetail implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(ComplaintsApi);

  protected readonly me = currentUserId();
  protected readonly staff = hasRole('AGENT', 'ADMIN');
  protected readonly admin = hasRole('ADMIN');
  protected readonly statusLabels = STATUS_LABELS;
  protected readonly categoryLabels = CATEGORY_LABELS;
  protected readonly priorityLabels = PRIORITY_LABELS;
  protected readonly roleLabels = ROLE_LABELS;
  protected readonly priorities = PRIORITIES;

  protected readonly complaint = signal<Complaint | null>(null);
  protected readonly loadError = signal('');
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly busy = signal(false);

  protected readonly message = signal('');
  protected readonly newStatus = signal<ComplaintStatus | ''>('');
  protected readonly statusComment = signal('');
  protected readonly newPriority = signal<ComplaintPriority | ''>('');

  protected readonly final = computed(() => {
    const c = this.complaint();
    return c ? isFinal(c.status) : true;
  });
  protected readonly isOwner = computed(() => this.complaint()?.userId === this.me);
  /** Modification : le client auteur, réclamation OPEN. */
  protected readonly canEdit = computed(() => this.isOwner() && this.complaint()?.status === 'OPEN');
  /** Suppression : le client auteur si OPEN, ou un ADMIN quel que soit le statut (règle de l'API). */
  protected readonly canDelete = computed(() => this.canEdit() || (this.admin && this.complaint() !== null));
  protected readonly canWrite = computed(() => !this.final() && (this.staff || this.isOwner()));

  private id = '';

  ngOnInit(): void {
    this.id = this.route.snapshot.paramMap.get('id') ?? '';
    const flash = (history.state as { flash?: string } | null)?.flash;
    if (flash) {
      this.notice.set(flash);
    }
    this.reload();
  }

  protected authorLabel(authorId: string, role: string): string {
    return authorId === this.me ? 'Vous' : (this.roleLabels[role as keyof typeof ROLE_LABELS] ?? role);
  }

  protected sendMessage(): void {
    const content = this.message().trim();
    if (!content) {
      return;
    }
    this.run(this.api.addMessage(this.id, content), () => {
      this.message.set('');
      this.reload('Message envoyé.');
    });
  }

  protected changeStatus(): void {
    const status = this.newStatus();
    if (!status) {
      return;
    }
    this.run(this.api.changeStatus(this.id, status, this.statusComment().trim() || null), (c) => {
      this.statusComment.set('');
      this.show(c, `Statut changé : ${this.statusLabels[status]}.`);
    });
  }

  protected changePriority(): void {
    const priority = this.newPriority();
    if (!priority || priority === this.complaint()?.priority) {
      return;
    }
    this.run(this.api.changePriority(this.id, priority), (c) => this.show(c, `Priorité changée : ${this.priorityLabels[priority]}.`));
  }

  protected remove(): void {
    const question = this.admin && !this.isOwner()
      ? 'Supprimer définitivement cette réclamation du client, avec ses messages et son historique ?'
      : 'Supprimer définitivement cette réclamation ?';
    if (!confirm(question)) {
      return;
    }
    this.run(this.api.delete(this.id), () => this.router.navigate(['/complaints'], { state: { flash: 'Réclamation supprimée.' } }));
  }

  private reload(notice = ''): void {
    this.api.get(this.id).subscribe({
      next: (c) => this.show(c, notice),
      error: (err) => this.loadError.set(complaintError(err, 'load')),
    });
  }

  private show(c: Complaint, notice: string): void {
    this.complaint.set(c);
    this.newStatus.set('');
    this.newPriority.set(c.priority);
    if (notice) {
      this.notice.set(notice);
    }
  }

  private run<T>(request: Observable<T>, done: (value: T) => void): void {
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        done(value);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(complaintError(err));
        // L'état a pu changer entre-temps (autre agent, 409) : on recharge pour afficher les actions à jour.
        this.reload();
      },
    });
  }
}
