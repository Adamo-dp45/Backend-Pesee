<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Security\Perimetre;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - L'ENTREPRISE NE SE SAISIT PAS, elle se déduit de qui crée. Un utilisateur appartient à une
      entreprise et à une seule : la demander revenait à faire choisir dans une liste d'un seul
      élément, et à faire voyager par le réseau une valeur que le serveur connaissait déjà.

    - CE N'ÉTAIT PAS UNE FAILLE, et il faut le dire pour que personne ne défasse ceci en croyant en
      reboucher une. Désigner l'entreprise d'un autre client ne plaçait rien chez lui : API Platform
      résout une IRI par le fournisseur d'item, où 'PerimetreExtension' s'applique aussi. L'IRI
      étrangère ne rendait donc rien, et la requête tombait en 400 « Item not found ».

    - Ce qu'on gagne quand même : le cloisonnement ne tient plus par EFFET DE BORD d'une résolution
      d'IRI, et le refus n'est plus un « introuvable » qui ne nomme rien. Il n'y a plus de refus du
      tout, puisqu'il n'y a plus rien à refuser.

    - On pose sans condition plutôt que de ne combler qu'un vide : si le champ revenait un jour dans
      les groupes d'écriture, un simple '??' laisserait le client décider en silence.

    - IL NE SE BRANCHE PAS TOUT SEUL, et c'est voulu : chaque opération le nomme. Toutes les entités
      d'entreprise ne sont pas dans ce cas — un SITE se rattache à une entreprise que seul le super
      administrateur choisit, et lui n'en a aucune. L'accrocher à l'interface le poserait là aussi,
      où il n'y a rien à déduire. Il sert aux référentiels dont le périmètre est entièrement
      déterminé par son auteur : le produit, les catégories de planteurs.
*/

/**
 * @implements ProcessorInterface<mixed, mixed>
 */
final class PerimetreEntrepriseProcessor implements ProcessorInterface
{
    public function __construct(
        private Perimetre $perimetre,
        // Retient l'auteur puis persiste : on s'intercale AVANT lui, on ne le remplace pas
        private AuditProcessor $audit
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if($data instanceof EntrepriseOwnedInterface) {
            $entreprise = $this->perimetre->entreprise();

            /*
                - Le super administrateur passe ici s'il force la requête : il n'a pas d'entreprise,
                  et aucune règle ne dirait laquelle choisir. Il n'a pas non plus le droit de créer
                  ces référentiels, donc ce refus ne devrait jamais s'afficher — il est là pour que
                  le jour où les droits bougent, la réponse soit un message et non une erreur de
                  base de données.
            */
            if($entreprise === null) {
                throw new UnprocessableEntityHttpException(
                    'Votre compte n\'est rattaché à aucune entreprise : il ne peut pas créer cet élément.'
                );
            }

            $data->setEntreprise($entreprise);
        }

        return $this->audit->process($data, $operation, $uriVariables, $context);
    }
}
