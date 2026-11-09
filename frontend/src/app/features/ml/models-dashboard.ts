import { DatePipe, DecimalPipe, PercentPipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { errorMessage } from '../../core/api';
import { MlApi, ModelVersion } from './ml-api';
import { MlNav } from './ml-nav';

/** Tableau de bord ADMIN : liste des versions du modèle, activation et nouvel entraînement. */
@Component({
  selector: 'app-models-dashboard',
  imports: [MlNav, DatePipe, DecimalPipe, PercentPipe],
  templateUrl: './models-dashboard.html',
  styleUrl: './ml-pages.scss',
})
export class ModelsDashboard {
  private readonly mlApi = inject(MlApi);

  protected readonly pageSize = 10;
  protected readonly pageIndex = signal(0);
  protected readonly versions = signal<ModelVersion[]>([]);
  protected readonly totalVersions = signal(0);
  protected readonly pageCount = computed(() =>
    Math.max(1, Math.ceil(this.totalVersions() / this.pageSize)),
  );

  protected readonly loading = signal(false);
  protected readonly training = signal(false);
  protected readonly activatingId = signal<string | null>(null);
  protected readonly errorText = signal('');
  protected readonly successText = signal('');

  constructor() {
    this.loadPage(0);
  }

  protected loadPage(pageIndex: number): void {
    this.loading.set(true);
    this.mlApi.listModels(pageIndex, this.pageSize).subscribe({
      next: (page) => {
        this.versions.set(page.items);
        this.totalVersions.set(page.total);
        this.pageIndex.set(page.page);
        this.loading.set(false);
      },
      error: (error) => {
        this.errorText.set(errorMessage(error));
        this.loading.set(false);
      },
    });
  }

  protected activate(modelVersion: ModelVersion): void {
    const confirmed = confirm(
      `Activer la version v${modelVersion.version} ? Elle calculera désormais tous les prix conseillés.`,
    );
    if (!confirmed) {
      return;
    }
    this.clearMessages();
    this.activatingId.set(modelVersion.id);
    this.mlApi.activateModel(modelVersion.id).subscribe({
      next: (activatedVersion) => {
        this.successText.set(`La version v${activatedVersion.version} est maintenant active.`);
        this.activatingId.set(null);
        this.loadPage(this.pageIndex());
      },
      error: (error) => {
        this.errorText.set(errorMessage(error));
        this.activatingId.set(null);
      },
    });
  }

  protected train(): void {
    this.clearMessages();
    this.training.set(true);
    this.mlApi.trainNewModel().subscribe({
      next: (newVersion) => {
        this.successText.set(
          `Version v${newVersion.version} (${newVersion.name}) entraînée : MAE ${newVersion.metrics.mae.toFixed(3)} TND. ` +
            `Elle est inactive : vérifiez ses métriques puis cliquez sur « Activer ».`,
        );
        this.training.set(false);
        this.loadPage(0);
      },
      error: (error) => {
        this.errorText.set(errorMessage(error));
        this.training.set(false);
      },
    });
  }

  private clearMessages(): void {
    this.errorText.set('');
    this.successText.set('');
  }
}
