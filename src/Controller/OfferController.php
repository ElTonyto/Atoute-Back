<?php

namespace App\Controller;

use App\Entity\Offer;
use App\Form\OfferType;
use App\Security\Voter\OfferVoter;
use App\Repository\OfferRepository;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(name="Offer")
 * @Route("/offers", name="offer_")
 */
final class OfferController extends BaseController
{
    private SerializerInterface $serializer;
    private EntityManagerInterface $entityManager;
    private FormFactoryInterface $formFactory;
    private OfferRepository $offerRepository;
    private AuthService $authService;

    public function __construct(
        SerializerInterface $serializer,
        EntityManagerInterface $entityManager,
        FormFactoryInterface $formFactory,
        AuthService $authService
    ) {
        $this->serializer = $serializer;
        $this->entityManager = $entityManager;
        $this->formFactory = $formFactory;
        $this->authService = $authService;
        $this->offerRepository = $entityManager->getRepository(Offer::class);
    }

    /**
     * @Route("", name="create", methods={"POST"})
     */
    public function create(Request $request): JsonResponse
    {
        $data = $this->testJson($request);

        if ($this->authService->getUser()->isParticular()) {
            return $this->respondWithError('only_companies_can_create_offer');
        }

        $form = $this->formFactory->create(OfferType::class);
        $form->submit($data);

        if (!$form->isValid()) {
            $errors = $this->getFormErrors($form);

            return $this->respondWithError('validation_errors', [
                'errors' => $errors
            ]);
        }

        $offer = $form->getData();

        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        $json = $this->serializer->serialize(
            $offer,
            'json',
            ['groups' => 'offer_read']
        );

        return $this->respond('offer_created', json_decode($json));
    }

    /**
     * @Route("", name="all", methods={"GET"})
     * @OA\Parameter(
     *     name="page",
     *     in="query",
     *     description="La pagination"
     * )
     * @OA\Parameter(
     *     name="l",
     *     in="query",
     *     description="Le nombre d'??l??ments (par d??faut ?? 10)"
     * )
     * * @OA\Parameter(
     *     name="type",
     *     in="query",
     *     description="Le type de contrat"
     * )
     * @OA\Parameter(
     *     name="activity",
     *     in="query",
     *     description="Le secteur d'activit??"
     * )
     * @OA\Parameter(
     *     name="salary",
     *     in="query",
     *     description="Le salaire par an"
     * )
     * @OA\Response(
     *     response=200,
     *     description="",
     *     @OA\JsonContent(
     *        type="object",
     *        @OA\Property(property="status", type="string"),
     *        @OA\Property(property="message", type="string"),
     *        @OA\Property(property="data", type="object"),
     *     )
     * )
     */
    public function all(Request $request, PaginatorInterface $paginator): JsonResponse
    {
        $limit = (int) $request->query->get('l') !== 0 ? (int) $request->query->get('l') : null;
        $offerPerPage = (int) $request->query->get('n') !== 0 ? (int) $request->query->get('n') : 6;

        // Filter values
        $type = (string) $request->query->get('type') !== "" ? (string) $request->query->get('type') : null;
        $activity = (string) $request->query->get('activity') !== "" ? (string) $request->query->get('activity') : null;
        $salary = (int) $request->query->get('salary') !== 0 ? (int) $request->query->get('salary') : null;
        $startAt = (string) $request->query->get('start') !== "" ? (string) $request->query->get('start') : null;
        $endAt = (string) $request->query->get('end') !== "" ? (string) $request->query->get('end') : null;

        // Filters array
        $filters = [];
        $filters["type"] = $type;
        $filters["activity"] = $activity;
        $filters["salary"] = $salary;
        $filters["startAt"] = $startAt;
        $filters["endAt"] = $endAt;

        $pagination = $paginator->paginate(
            $this->offerRepository->getPublishQuery($limit, $filters), // Requ??te contenant les donn??es ?? paginer (ici nos articles)
            $request->query->getInt('page', 1), // Num??ro de la page en cours, pass?? dans l'URL, 1 si aucune page
            $offerPerPage// Nombre de r??sultats par page
        );

        $json = $this->serializer->serialize(
            $pagination,
            'json',
            ['groups' => 'offer_read']
        );

        return $this->respond('offers_infos', json_decode($json));
    }

    /**
     * @Route("/{id}", name="index", methods={"GET"})
     */
    public function index(int $id): JsonResponse
    {
        $offer = $this->getAndVerifyOffer($id);

        if (!$offer instanceof Offer) {
            return $this->respondWithError($offer);
        }

        $json = $this->serializer->serialize(
            $offer,
            'json',
            ['groups' => 'offer_read']
        );

        return $this->respond('offer_infos', json_decode($json));
    }

    /**
     * @Route("/{id}", name="update", methods={"PATCH"})
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $offer = $this->getAndVerifyOffer($id);

        if (!$offer instanceof Offer) {
            return $this->respondWithError($offer);
        }

        if (!$offer->isOwner($this->authService->getUser())) {
            return $this->respondWithError('only_companies_can_create_offer');
        }

        $data = $this->testJson($request);

        $form = $this->formFactory->create(OfferType::class, $offer);
        $form->submit($data);

        if (!$form->isValid()) {
            $errors = $this->getFormErrors($form);

            return $this->respondWithError('validation_errors', [
                'errors' => $errors
            ]);
        }

        $this->entityManager->flush();

        return $this->respond('offer_updated');
    }

    /**
     * @Route("/{id}", name="remove", methods={"DELETE"})
     */
    public function remove(int $id): JsonResponse
    {
        $offer = $this->getAndVerifyOffer($id);

        if (!$offer instanceof Offer) {
            return $this->respondWithError($offer);
        }

        if (!$offer->isOwner($this->authService->getUser())) {
            return $this->respondWithError('not_offer_owner');
        }

        $this->entityManager->remove($offer);
        $this->entityManager->flush();

        return $this->respond('offer_removed');
    }

    /**
     * @Route("/{id}/applications", name="applications", methods={"GET"})
     */
    public function applications(int $id): JsonResponse
    {
        $offer = $this->getAndVerifyOffer($id);

        if (!$offer instanceof Offer) {
            return $this->respondWithError($offer);
        }

        if (!$offer->isOwner($this->authService->getUser())) {
            return $this->respondWithError('not_offer_owner');
        }

        $applications = [];
        foreach ($offer->getApplicationsToBeProcessed() as $application) {
            $applications[] = json_decode($this->serializer->serialize($application, 'json', ['groups' => 'application_offer_read']));
        }

        return $this->respond('', $applications);
    }

    /**
     * @return Offer|string
     */
    private function getAndVerifyOffer(int $id)
    {
        if (!$offer = $this->offerRepository->find($id)) {
            return 'offer_not_found';
        }

        return $offer;
    }
}