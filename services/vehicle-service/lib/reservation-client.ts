// Appel à RENTAL-RESERVATION : GET /internal/reservations/active?vehicleId=
// Phase 1 : simulé (RESERVATION_MOCK=true) avec les réservations de docs/demo-ids.md
// Phase 2 : vrai appel via Eureka (404 / 503 / 502 selon les conventions)

const MOCK_ACTIVE: Record<string, string[]> = {
  // v1 -> r-pending (PENDING)
  "ecdcc60f-ed6a-4007-8622-2b568338ef7a": ["01cb68ad-4eec-4bdd-8971-ce35381c59d1"],
  // v2 -> r-confirmed (CONFIRMED)
  "9f03ab2d-97a5-4c08-a952-dc6e446370d1": ["33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8"],
};

export type ActiveReservations = {
  vehicleId: string;
  active: boolean;
  reservationIds: string[];
};

export async function getActiveReservations(vehicleId: string): Promise<ActiveReservations> {
  if (process.env.RESERVATION_MOCK === "true") {
    const ids = MOCK_ACTIVE[vehicleId] ?? [];
    return { vehicleId, active: ids.length > 0, reservationIds: ids };
  }
  // TODO phase 2 : appel réel via Eureka
  throw new Error("Appel réel à reservation-service pas encore implémenté");
}