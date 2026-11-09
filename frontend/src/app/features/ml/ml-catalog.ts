/**
 * Catégories et agences PROVISOIRES proposées dans le simulateur de prix.
 *
 * Copie volontaire de services/ml-service/app/rental_catalog.py : mêmes UUID que docs/demo-ids.md.
 * À remplacer plus tard par les données du vehicle-service (Yosra) quand ses routes seront prêtes.
 * Si la liste change, mettre à jour les deux fichiers.
 */
export interface CatalogOption {
  id: string;
  label: string;
}

export const CATEGORY_OPTIONS: CatalogOption[] = [
  { id: '94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc', label: 'Économique (tarif de base 90 TND)' },
  { id: 'a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c', label: 'SUV (tarif de base 160 TND)' },
  { id: '98d95d25-4112-4e52-8fbf-d2d908239b34', label: 'Luxe (tarif de base 300 TND)' },
];

export const AGENCY_OPTIONS: CatalogOption[] = [
  { id: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e', label: 'Agence Tunis' },
  { id: '1deb1dea-ab54-436d-8ed7-c3322f782c88', label: 'Agence Sousse' },
];
