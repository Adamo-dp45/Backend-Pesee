<?php

namespace App\Entity\Output;

use App\Domain\Enum\OrigineTarif;
use Symfony\Component\Serializer\Attribute\Groups;

/* Un produit, ce que ce planteur touche dessus, et lequel des trois sauts a répondu. */
final class LigneTarifApplicable
{
    public function __construct(
        #[Groups(['read:TarifsApplicables'])]
        public readonly int $produit,

        #[Groups(['read:TarifsApplicables'])]
        public readonly string $libelle,

        /* Le prix du produit, celui de tous ceux qui n'ont rien de particulier. */
        #[Groups(['read:TarifsApplicables'])]
        public readonly int $prixProduit,

        /* Ce que CE planteur touche. Zéro si le produit n'est pas tarifé : ses pesées ne valent rien. */
        #[Groups(['read:TarifsApplicables'])]
        public readonly int $prix,

        #[Groups(['read:TarifsApplicables'])]
        public readonly string $origine,

        #[Groups(['read:TarifsApplicables'])]
        public readonly string $origineLibelle,

        /*
            - CE QU'IL AURAIT SANS SON TARIF PROPRE : le tarif de sa catégorie, ou le prix du produit.
              C'est ce que l'écran affiche sous une case vide — « le laisser vide, c'est le payer
              tant » — là où rappeler le prix du produit induirait en erreur un planteur dont la
              catégorie paie autrement.
        */
        #[Groups(['read:TarifsApplicables'])]
        public readonly int $prixSansTarifPropre,

        /* Vrai s'il a un tarif négocié à lui sur ce produit. */
        #[Groups(['read:TarifsApplicables'])]
        public readonly bool $sien,
    )
    {
    }

    public static function depuis(
        int $produit,
        string $libelle,
        int $prixProduit,
        int $prix,
        OrigineTarif $origine,
        int $prixSansTarifPropre
    ): self
    {
        return new self(
            $produit,
            $libelle,
            $prixProduit,
            $prix,
            $origine->value,
            $origine->libelle(),
            $prixSansTarifPropre,
            $origine === OrigineTarif::PLANTEUR
        );
    }
}
