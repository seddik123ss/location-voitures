<?php

namespace App\DataFixtures;

use App\Entity\Complaint;
use App\Enum\AuthorRole;
use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use App\Enum\ComplaintStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Données de démo cohérentes avec docs/demo-ids.md :
 * chaque réclamation porte sur une réservation ou un contrat du même client.
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        foreach ($this->complaints() as $complaint) {
            $manager->persist($complaint);
        }
        $manager->flush();
    }

    /** @return list<Complaint> */
    private function complaints(): array
    {
        // rec-1 : client2, r-completed, BILLING, OPEN
        $rec1 = new Complaint(
            DemoIds::CLIENT2,
            ComplaintCategory::BILLING,
            ComplaintPriority::MEDIUM,
            'Montant facturé supérieur au devis',
            'La réservation du 1er au 5 octobre était annoncée à 640.000 TND, mais un supplément apparaît sur mon relevé.',
            reservationId: DemoIds::R_COMPLETED,
            id: DemoIds::REC_1,
            createdAt: $this->at('2026-10-06T09:15:00Z'),
        );
        $rec1->addMessage(DemoIds::CLIENT2, AuthorRole::CLIENT, 'Je joins la capture de mon relevé bancaire, merci de vérifier.', $this->at('2026-10-06T09:20:00Z'));

        // rec-2 : client2, r-completed, VEHICLE, RESOLVED
        $rec2 = new Complaint(
            DemoIds::CLIENT2,
            ComplaintCategory::VEHICLE,
            ComplaintPriority::HIGH,
            'Voyant moteur allumé pendant la location',
            'Le voyant moteur de la Kia Sportage s\'est allumé le deuxième jour, j\'ai dû m\'arrêter sur l\'autoroute.',
            reservationId: DemoIds::R_COMPLETED,
            id: DemoIds::REC_2,
            createdAt: $this->at('2026-10-03T16:40:00Z'),
        );
        $rec2->changeStatus(ComplaintStatus::IN_PROGRESS, DemoIds::AGENT1, AuthorRole::AGENT, 'Prise en charge par l\'agence de Sousse', $this->at('2026-10-03T17:05:00Z'));
        $rec2->addMessage(DemoIds::AGENT1, AuthorRole::AGENT, 'Nous avons signalé le véhicule à l\'atelier. Pouvez-vous nous indiquer le kilométrage affiché ?', $this->at('2026-10-03T17:10:00Z'));
        $rec2->addMessage(DemoIds::CLIENT2, AuthorRole::CLIENT, 'Environ 18 240 km.', $this->at('2026-10-03T17:45:00Z'));
        $rec2->addMessage(DemoIds::AGENT1, AuthorRole::AGENT, 'Capteur défectueux remplacé, un geste commercial a été appliqué sur votre facture.', $this->at('2026-10-07T10:00:00Z'));
        $rec2->changeStatus(ComplaintStatus::RESOLVED, DemoIds::AGENT1, AuthorRole::AGENT, 'Capteur remplacé, geste commercial accordé', $this->at('2026-10-07T10:02:00Z'));

        // client1, r-confirmed, SERVICE, IN_PROGRESS
        $service = new Complaint(
            DemoIds::CLIENT1,
            ComplaintCategory::SERVICE,
            ComplaintPriority::LOW,
            'Agence injoignable par téléphone',
            'Impossible de joindre l\'agence de Tunis pour confirmer l\'heure de prise en charge du 20 novembre.',
            reservationId: DemoIds::R_CONFIRMED,
            id: DemoIds::EXTRA_SERVICE,
            createdAt: $this->at('2026-11-05T08:30:00Z'),
        );
        $service->changeStatus(ComplaintStatus::IN_PROGRESS, DemoIds::AGENT1, AuthorRole::AGENT, null, $this->at('2026-11-05T11:00:00Z'));
        $service->addMessage(DemoIds::AGENT1, AuthorRole::AGENT, 'Désolés pour l\'attente, nous vous rappelons aujourd\'hui avant 17 h.', $this->at('2026-11-05T11:02:00Z'));

        // client1, r-pending, BILLING, REJECTED (par admin1)
        $billing = new Complaint(
            DemoIds::CLIENT1,
            ComplaintCategory::BILLING,
            ComplaintPriority::MEDIUM,
            'Caution trop élevée',
            'La caution de 500.000 TND me semble excessive pour une Peugeot 208.',
            reservationId: DemoIds::R_PENDING,
            id: DemoIds::EXTRA_BILLING,
            createdAt: $this->at('2026-11-02T14:00:00Z'),
        );
        $billing->changeStatus(ComplaintStatus::REJECTED, DemoIds::ADMIN1, AuthorRole::ADMIN, 'Montant conforme au tarif de la catégorie Économique', $this->at('2026-11-03T09:30:00Z'));

        // client2, c-completed, DAMAGE_DISPUTE, OPEN (rattachée au contrat)
        $damage = new Complaint(
            DemoIds::CLIENT2,
            ComplaintCategory::DAMAGE_DISPUTE,
            ComplaintPriority::HIGH,
            'Rayure contestée au retour',
            'La rayure sur le pare-chocs arrière figurait déjà sur l\'état des lieux de départ.',
            contractId: DemoIds::C_COMPLETED,
            id: DemoIds::EXTRA_DAMAGE,
            createdAt: $this->at('2026-10-08T12:00:00Z'),
        );

        return [$rec1, $rec2, $service, $billing, $damage];
    }

    private function at(string $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable($instant);
    }
}
