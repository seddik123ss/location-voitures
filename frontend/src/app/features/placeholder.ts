import { Component, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';

/** Page provisoire affichée tant que le membre n'a pas codé son module. */
@Component({
  selector: 'app-placeholder',
  template: `
    <section class="placeholder">
      <h1>{{ data['title'] }}</h1>
      <p>Module à développer par <strong>{{ data['owner'] }}</strong>.</p>
      <p class="hint">Fichier de routes : <code>src/app/features/{{ data['module'] }}/{{ data['module'] }}.routes.ts</code></p>
    </section>
  `,
  styles: `
    .placeholder { padding: 32px; }
    .hint { color: var(--muted); }
  `,
})
export class Placeholder {
  protected readonly data = inject(ActivatedRoute).snapshot.data;
}
