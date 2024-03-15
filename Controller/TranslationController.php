<?php

namespace Lexik\Bundle\TranslationBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\TranslationBundle\Entity\Translation;
use Lexik\Bundle\TranslationBundle\Entity\TransUnit;
use Lexik\Bundle\TranslationBundle\Form\Handler\TransUnitFormHandler;
use Lexik\Bundle\TranslationBundle\Form\Type\TransUnitType;
use Lexik\Bundle\TranslationBundle\Manager\LocaleManagerInterface;
use Lexik\Bundle\TranslationBundle\Storage\StorageInterface;
use Lexik\Bundle\TranslationBundle\Translation\Translator;
use Lexik\Bundle\TranslationBundle\Util\Csrf\CsrfCheckerTrait;
use Lexik\Bundle\TranslationBundle\Util\Overview\StatsAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Cédric Girard <c.girard@lexik.fr>
 */
class TranslationController extends AbstractController
{
    use CsrfCheckerTrait;

    public function __construct(
        private readonly StorageInterface $translationStorage,
        private readonly StatsAggregator $statsAggregator,
        private readonly TransUnitFormHandler $transUnitFormHandler,
        private readonly Translator $lexikTranslator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleManagerInterface $localeManager,
        private readonly ?\Lexik\Bundle\TranslationBundle\Util\Profiler\TokenFinder $tokenFinder,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    /**
     * Display an overview of the translation status per domain.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function overviewAction()
    {
        $stats = $this->statsAggregator->getStats();

        return $this->render('@LexikTranslation/Translation/overview.html.twig', ['layout'         => $this->getParameter('lexik_translation.base_layout'), 'locales'        => $this->getManagedLocales(), 'domains'        => $this->translationStorage->getTransUnitDomains(), 'latestTrans'    => $this->translationStorage->getLatestUpdatedAt(), 'stats'          => $stats]);
    }

    /**
     * Display the translation grid.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function gridAction(Request $request)
    {
        $translations = $this->entityManager->getRepository(TransUnit::class)->getTransUnitList($this->getManagedLocales());
        $translationsCount = $this->entityManager->getRepository(TransUnit::class)->count();

        $tokens = null;
        if ($this->getParameter('lexik_translation.dev_tools.enable') && $this->tokenFinder !== null) {
            $tokens = $this->tokenFinder->find();
        }

        return $this->render('@LexikTranslation/Translation/grid.html.twig', [
            'layout'         => $this->getParameter('lexik_translation.base_layout'),
            'inputType'      => $this->getParameter('lexik_translation.grid_input_type'),
            'autoCacheClean' => $this->getParameter('lexik_translation.auto_cache_clean'),
            'toggleSimilar'  => $this->getParameter('lexik_translation.grid_toggle_similar'),
            'locales'        => $this->getManagedLocales(),
            'tokens'         => $tokens,
            'lexikTranslations' => $translations,
            'page' => 1,
            'translationsCount' => $translationsCount
        ]);
    }

    /**
     * Remove cache files for managed locales.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function invalidateCacheAction(Request $request)
    {
        $this->lexikTranslator->removeLocalesCacheFiles($this->getManagedLocales());

        $message = $this->translator->trans('translations.cache_removed', [], 'LexikTranslationBundle');

        if ($request->isXmlHttpRequest()) {
            $this->checkCsrf();

            return new JsonResponse(['message' => $message]);
        }

        $request->getSession()->getFlashBag()->add('success', $message);

        return $this->redirect($this->generateUrl('lexik_translation_grid'));
    }

    /**
     * Add a new trans unit with translation for managed locales.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function newAction(Request $request)
    {
        $form = $this->createForm(TransUnitType::class, $this->transUnitFormHandler->createFormData(), $this->transUnitFormHandler->getFormOptions());

        if ($this->transUnitFormHandler->process($form, $request)) {
            $message = $this->translator->trans('translations.successfully_added', [], 'LexikTranslationBundle');

            $request->getSession()->getFlashBag()->add('success', $message);

            $redirectUrl = $form->get('save_add')->isClicked() ? 'lexik_translation_new' : 'lexik_translation_grid';

            return $this->redirect($this->generateUrl($redirectUrl));
        }

        return $this->render('@LexikTranslation/Translation/new.html.twig', ['layout' => $this->getParameter('lexik_translation.base_layout'), 'form'   => $form->createView()]);
    }

    public function loadTranslationGrid(Request $request): Response
    {
        $sortOrder = $request->request->get('sort');
        $columnToOrderBy = $request->request->get('columnToOrderBy');
        $page = $request->request->has('page') ? $request->request->get('page') : null;

        $translations = $this->entityManager->getRepository(TransUnit::class)->getTransUnitList($this->getManagedLocales(), 20, $page, array('sidx' => $columnToOrderBy, 'sord' => $sortOrder));

        return $this->render('@LexikTranslation/Translation/_ngGrid.html.twig', [
            'locales' => $this->getManagedLocales(),
            'lexikTranslations' => $translations,
            'page' => $page
        ]);
    }

    public function saveUpdates(Request $request): JsonResponse
    {
        $transUnitId = $request->request->get('id');
        $locale = $request->request->get('locale');
        $newLexikTranslationValue = $request->request->get('newvalue');
        $columnToUpdate = $request->request->get('column');

        $lexikUnitTranslation = $this->entityManager->getRepository(Translation::class)->findOneBy(['transUnit' => $transUnitId, 'locale' => $locale]);

        if ($columnToUpdate === 'translation') {
            $lexikUnitTranslation->setContent($newLexikTranslationValue);
        } else {
            $lexikUnit = $this->entityManager->getRepository(TransUnit::class)->findOneBy(['id' => $transUnitId]);
            $this->entityManager->remove($lexikUnit);
        }

        $this->entityManager->flush();

        return new JsonResponse(array(
            'type' => 'success'
        ));
    }

    public function filterLexikTranslations(Request $request): Response
    {
        $column = $request->request->get('column');
        $filterValue = $request->request->get('filterValue');
        $columnType = $request->request->get('columnType');

        if ($filterValue !== '') {
            $lexikUnitTranslations = $this->entityManager->getRepository(TransUnit::class)->findTranslationsUnitByFilterColumn($column, $filterValue, $columnType);
        } else {
            $lexikUnitTranslations = $this->entityManager->getRepository(TransUnit::class)->getTransUnitList($this->getManagedLocales());
        }

        if (count($lexikUnitTranslations) > 0) {
            return $this->render('@LexikTranslation/Translation/_ngGrid.html.twig', [
                'locales' => $this->getManagedLocales(),
                'lexikTranslations' => $lexikUnitTranslations
            ]);
        } else {
            return $this->render('@LexikTranslation/Translation/noTranslations.html.twig');
        }
    }

    /**
     * Returns managed locales.
     *
     * @return array
     */
    protected function getManagedLocales()
    {
        return $this->localeManager->getLocales();
    }
}
