<?php

namespace App\State\Provider;

use Symfony\Component\HttpFoundation\RequestStack;

/*
    - Lecture de la plage de dates demandée, commune à tous les écrans de statistiques.
    - Par défaut le mois en cours : c'est ce que les utilisateurs regardent en arrivant, et ça
      évite d'agréger des années de pesées quand personne n'a rien précisé.
    - Les bornes couvrent la journée entière, comme le fait l'écran « Bilan standard » du poste
      avec ses heures 00:00:01 et 23:59:59.
*/
final class Periode
{
    public function __construct(
        public readonly ?\DateTimeImmutable $du,
        public readonly ?\DateTimeImmutable $au
    ) {
    }

    public static function depuis(RequestStack $requetes): self
    {
        $requete = $requetes->getCurrentRequest();

        $du = self::borne($requete?->query->get('du'), '00:00:00')
            ?? new \DateTimeImmutable('first day of this month 00:00:00');

        $au = self::borne($requete?->query->get('au'), '23:59:59')
            ?? new \DateTimeImmutable('last day of this month 23:59:59');

        return new self($du, $au);
    }

    /**
     * @return array{du: string|null, au: string|null}
     */
    public function versTableau(): array
    {
        return [
            'du' => $this->du?->format('Y-m-d'),
            'au' => $this->au?->format('Y-m-d'),
        ];
    }

    private static function borne(mixed $valeur, string $heure): ?\DateTimeImmutable
    {
        if (!is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        $horodatage = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($valeur) . ' ' . $heure);

        return $horodatage === false ? null : $horodatage;
    }
}
