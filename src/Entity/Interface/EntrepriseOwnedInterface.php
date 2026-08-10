<?php

namespace App\Entity\Interface;

use App\Entity\Entreprise;

/*
    - Marque une entité qui porte directement son entreprise. 'PerimetreExtension' ajoute alors
      'entreprise = :entreprise' à toutes les lectures, et 'AccesVoter' vérifie l'objet chargé.
*/
interface EntrepriseOwnedInterface
{
    public function getEntreprise(): ?Entreprise;

    public function setEntreprise(?Entreprise $entreprise): static;
}
