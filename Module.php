<?php

/*
 * Copyright Daniel Berthereau, 2017-2019
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Generateur;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generateur\Entity\Generation;
use Generateur\Permissions\Acl;
use Generic\AbstractModule;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Entity\AbstractEntity;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\Permissions\Acl\Acl as ZendAcl;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'CustomVocab';

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        // TODO Add filters (don't display when resource is private, like media?).
        // TODO Set Acl public rights to false when the visibility filter will be ready.
        // $this->addEntityManagerFilters();
        $this->addAclRoleAndRules();
    }

    public function install(ServiceLocatorInterface $services)
    {
        $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
        if ($module && version_compare($module->getIni('version'), '3.0.17', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                'Generic', '3.0.17'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException($message);
        }

        parent::install($services);
    }

    protected function postInstall()
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        // TODO Replace the resource templates for generations that are not items.

        $resourceTemplateSettings = [
            'Generation' => [
                'oa:motivatedBy' => 'oa:Generation',
                'rdf:value' => 'oa:hasBody',
                'oa:hasPurpose' => 'oa:hasBody',
                'dcterms:language' => 'oa:hasBody',
                'oa:hasSource' => 'oa:hasTarget',
                'rdf:type' => 'oa:hasTarget',
                'dcterms:format' => 'oa:hasTarget',
            ],
        ];

        $resourceTemplateData = $settings->get('generateur_resource_template_data', []);
        foreach ($resourceTemplateSettings as $label => $data) {
            try {
                $resourceTemplate = $api->read('resource_templates', ['label' => $label])->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                $message = new \Omeka\Stdlib\Message(
                    'The settings to manage the generation template are not saved. You shoud edit the resource template "Generation" manually.' // @translate
                );
                $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
                $messenger->addWarning($message);
                continue;
            }
            // Add the special resource template settings.
            $resourceTemplateData[$resourceTemplate->id()] = $data;
        }
        $settings->set('generateur_resource_template_data', $resourceTemplateData);
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
        $services = $serviceLocator;

        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        if (!empty($_POST['remove-vocabulary'])) {
            $prefix = 'rdf';
            $installResources->removeVocabulary($prefix);
            $prefix = 'oa';
            $installResources->removeVocabulary($prefix);
        }

        if (!empty($_POST['remove-custom-vocab'])) {
            $customVocab = 'Generation oa:motivatedBy';
            $installResources->removeCustomVocab($customVocab);
            $customVocab = 'Generation Body oa:hasPurpose';
            $installResources->removeCustomVocab($customVocab);
            $customVocab = 'Generation Target dcterms:format';
            $installResources->removeCustomVocab($customVocab);
            $customVocab = 'Generation Target rdf:type';
            $installResources->removeCustomVocab($customVocab);
        }

        if (!empty($_POST['remove-template'])) {
            $resourceTemplate = 'Generation';
            $installResources->removeResourceTemplate($resourceTemplate);
        }

        parent::uninstall($serviceLocator);
    }

    public function warnUninstall(Event $event)
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $serviceLocator = $this->getServiceLocator();
        $t = $serviceLocator->get('MvcTranslator');

        $vocabularyLabels = 'RDF Concepts" / "Web Generation Ontology';
        $customVocabs = 'Generation oa:motivatedBy" / "oa:hasPurpose" / "rdf:type" / "dcterms:format';
        $resourceTemplates = 'Generation';

        $html = '<p>';
        $html .= '<strong>';
        $html .= $t->translate('WARNING'); // @translate
        $html .= '</strong>' . ': ';
        $html .= '</p>';

        $html .= '<p>';
        $html .= $t->translate('All the generations will be removed.'); // @translate
        $html .= '</p>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('If checked, the values of the vocabularies "%s" will be removed too. The class of the resources that use a class of these vocabularies will be reset.'), // @translate
            $vocabularyLabels
        );
        $html .= '</p>';
        $html .= '<label><input name="remove-vocabulary" type="checkbox" form="confirmform">';
        $html .= sprintf($t->translate('Remove the vocabularies "%s"'), $vocabularyLabels); // @translate
        $html .= '</label>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('If checked, the custom vocabs "%s" will be removed too.'), // @translate
            $customVocabs
        );
        $html .= '</p>';
        $html .= '<label><input name="remove-custom-vocab" type="checkbox" form="confirmform">';
        $html .= sprintf($t->translate('Remove the custom vocabs "%s"'), $customVocabs); // @translate
        $html .= '</label>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('If checked, the resource templates "%s" will be removed too. The resource template of the resources that use it will be reset.'), // @translate
            $resourceTemplates
        );
        $html .= '</p>';
        $html .= '<label><input name="remove-template" type="checkbox" form="confirmform">';
        $html .= sprintf($t->translate('Remove the resource templates "%s"'), $resourceTemplates); // @translate
        $html .= '</label>';

        echo $html;
    }

    /**
     * Add ACL role and rules for this module.
     *
     * @todo Keep rights for Generation only (body and  target are internal classes).
     */
    protected function addAclRoleAndRules()
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered, so Guest come after Generateur.
        // See \Guest\Module::onBootstrap().
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }

        $acl
            ->addRole(Acl::ROLE_ANNOTATOR)
            ->addRoleLabel(Acl::ROLE_ANNOTATOR, 'Annotator'); // @translate

        $settings = $services->get('Omeka\Settings');
        // TODO Set rights to false when the visibility filter will be ready.
        // TODO Check if public can generateur and flag, and read generations and own ones.
        $publicViewGenerateur = $settings->get('generateur_public_allow_view', true);
        if ($publicViewGenerateur) {
            $publicAllowGenerateur = $settings->get('generateur_public_allow_generateur', false);
            if ($publicAllowGenerateur) {
                $this->addRulesForVisitorAnnotators($acl);
            } else {
                $this->addRulesForVisitors($acl);
            }
        }

        // Identified users can generateur. Reviewer and above can approve. Admins
        // can delete.
        $this->addRulesForAnnotator($acl);
        $this->addRulesForAnnotators($acl);
        $this->addRulesForApprobators($acl);
        $this->addRulesForAdmins($acl);
    }

    /**
     * Add ACL rules for visitors (read only).
     *
     * @todo Add rights to update generation (flag only).
     *
     * @param ZendAcl $acl
     */
    protected function addRulesForVisitors(ZendAcl $acl)
    {
        $acl
            ->allow(
                null,
                [Generation::class],
                ['read']
            )
            ->allow(
                null,
                [Api\Adapter\GenerationAdapter::class],
                ['search', 'read']
            )
            ->allow(
                null,
                [Controller\Site\GenerationController::class],
                ['index', 'browse', 'show', 'search', 'flag']
            );
    }

    /**
     * Add ACL rules for annotator visitors.
     *
     * @param ZendAcl $acl
     */
    protected function addRulesForVisitorAnnotators(ZendAcl $acl)
    {
        $acl
            ->allow(
                null,
                [Generation::class],
                ['read', 'create']
            )
            ->allow(
                null,
                [Api\Adapter\GenerationAdapter::class],
                ['search', 'read', 'create']
            )
            ->allow(
                null,
                [Controller\Site\GenerationController::class],
                ['index', 'browse', 'show', 'search', 'add', 'flag']
            );
    }

    /**
     * Add ACL rules for annotator.
     *
     * @param ZendAcl $acl
     */
    protected function addRulesForAnnotator(ZendAcl $acl)
    {
        // The annotator has less rights than Researcher for core resources, but
        // similar rights for generations that Author has for core resources.
        // The rights related to generation are set with all other annotators.
        $acl
            ->allow(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                [
                    'Omeka\Controller\Admin\Index',
                    'Omeka\Controller\Admin\Item',
                    'Omeka\Controller\Admin\ItemSet',
                    'Omeka\Controller\Admin\Media',
                ],
                [
                    'index',
                    'browse',
                    'show',
                    'show-details',
                ]
            )

            ->allow(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                [
                    'Omeka\Controller\Admin\Item',
                    'Omeka\Controller\Admin\ItemSet',
                    'Omeka\Controller\Admin\Media',
                ],
                [
                    'search',
                    'sidebar-select',
                ]
            )

            ->allow(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                ['Omeka\Controller\Admin\User'],
                ['show', 'edit']
            )
            ->allow(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                ['Omeka\Api\Adapter\UserAdapter'],
                ['read', 'update', 'search']
            )
            ->allow(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                [\Omeka\Entity\User::class],
                ['read']
            )
            ->allow(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                [\Omeka\Entity\User::class],
                ['update', 'change-password', 'edit-keys'],
                new \Omeka\Permissions\Assertion\IsSelfAssertion
            )

            // TODO Remove this rule for Omeka >= 1.2.1.
            ->deny(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                [
                    'Omeka\Controller\SiteAdmin\Index',
                    'Omeka\Controller\SiteAdmin\Page',
                ]
            )
            ->deny(
                [\Generateur\Permissions\Acl::ROLE_ANNOTATOR],
                ['Omeka\Controller\Admin\User'],
                ['browse']
            );
    }

    /**
     * Add ACL rules for annotators (not visitor).
     *
     * @param ZendAcl $acl
     */
    protected function addRulesForAnnotators(ZendAcl $acl)
    {
        $annotators = [
            \Generateur\Permissions\Acl::ROLE_ANNOTATOR,
            \Omeka\Permissions\Acl::ROLE_RESEARCHER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
        ];
        $acl
            ->allow(
                $annotators,
                [Generation::class],
                ['create']
            )
            ->allow(
                $annotators,
                [Generation::class],
                ['update', 'delete'],
                new \Omeka\Permissions\Assertion\OwnsEntityAssertion
            )
            ->allow(
                $annotators,
                [Api\Adapter\GenerationAdapter::class],
                ['search', 'read', 'create', 'update', 'delete', 'batch_create', 'batch_update', 'batch_delete']
            )
            ->allow(
                $annotators,
                [Controller\Site\GenerationController::class]
            )
            ->allow(
                $annotators,
                [Controller\Admin\GenerationController::class],
                ['index', 'search', 'browse', 'show', 'show-details', 'add', 'edit', 'delete', 'delete-confirm', 'flag']
            );
    }

    /**
     * Add ACL rules for approbators.
     *
     * @param ZendAcl $acl
     */
    protected function addRulesForApprobators(ZendAcl $acl)
    {
        // Admin are approbators too, but rights are set below globally.
        $approbators = [
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
        ];
        // "view-all" is added via main acl factory for resources.
        $acl
            ->allow(
                [\Omeka\Permissions\Acl::ROLE_REVIEWER],
                [Generation::class],
                ['read', 'create', 'update']
            )
            ->allow(
                [\Omeka\Permissions\Acl::ROLE_REVIEWER],
                [Generation::class],
                ['delete'],
                new \Omeka\Permissions\Assertion\OwnsEntityAssertion
            )
            ->allow(
                [\Omeka\Permissions\Acl::ROLE_EDITOR],
                [Generation::class],
                ['read', 'create', 'update', 'delete']
            )
            ->allow(
                $approbators,
                [Api\Adapter\GenerationAdapter::class],
                ['search', 'read', 'create', 'update', 'delete', 'batch_create', 'batch_update', 'batch_delete']
            )
            ->allow(
                $approbators,
                [Controller\Site\GenerationController::class]
            )
            ->allow(
                $approbators,
                Controller\Admin\GenerationController::class,
                [
                    'index',
                    'search',
                    'browse',
                    'show',
                    'show-details',
                    'add',
                    'edit',
                    'delete',
                    'delete-confirm',
                    'flag',
                    'batch-approve',
                    'batch-unapprove',
                    'batch-flag',
                    'batch-unflag',
                    'batch-set-spam',
                    'batch-set-not-spam',
                    'toggle-approved',
                    'toggle-flagged',
                    'toggle-spam',
                    'batch-delete',
                    'batch-delete-all',
                    'batch-update',
                    'approve',
                    'unflag',
                    'set-spam',
                    'set-not-spam',
                    'show-details',
                ]
            );
    }

    /**
     * Add ACL rules for approbators.
     *
     * @param ZendAcl $acl
     */
    protected function addRulesForAdmins(ZendAcl $acl)
    {
        $admins = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
        ];
        $acl
            ->allow(
                $admins,
                [
                    Generation::class,
                    Api\Adapter\GenerationAdapter::class,
                    Controller\Site\GenerationController::class,
                    Controller\Admin\GenerationController::class,
                ]
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Add the Open Generation part to the representation.
        $representations = [
            'user' => UserRepresentation::class,
            'item_sets' => ItemSetRepresentation::class,
            'items' => ItemRepresentation::class,
            'media' => MediaRepresentation::class,
        ];
        foreach ($representations as $representation) {
            $sharedEventManager->attach(
                $representation,
                'rep.resource.json',
                [$this, 'filterJsonLd']
            );
        }

        // TODO Add the special data to the resource template.

        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'addHeadersAdmin']
        );

        // Allows to search resource template by resource class.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
            'api.search.query',
            [$this, 'searchQueryResourceTemplate']
        );

        // Events for the public front-end.
        $controllers = [
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ];
        foreach ($controllers as $controller) {
            // Add the generations to the resource show public pages.
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'displayPublic']
            );
        }

        // Manage the search query with special fields that are not present in
        // default search form.
        $sharedEventManager->attach(
            \Generateur\Controller\Admin\GenerationController::class,
            'view.advanced_search',
            [$this, 'displayAdvancedSearchGeneration']
        );
        // Filter the search filters for the advanced search pages.
        $sharedEventManager->attach(
            \Generateur\Controller\Admin\GenerationController::class,
            'view.search.filters',
            [$this, 'filterSearchFiltersGeneration']
        );

        // Events for the admin board.
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            $sharedEventManager->attach(
                $controller,
                'view.show.section_nav',
                [$this, 'addTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'displayListAndForm']
            );

            // Add the details to the resource browse admin pages.
            $sharedEventManager->attach(
                $controller,
                'view.details',
                [$this, 'viewDetails']
            );

            // Add the tab form to the resource edit admin pages.
            // Note: it can't be added to the add form, because it has no sense
            // to generateur something that does not exist.
            $sharedEventManager->attach(
                $controller,
                'view.edit.section_nav',
                [$this, 'addTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.edit.form.after',
                [$this, 'displayList']
            );
        }

        // Add a tab to the resource template admin pages.
        // Can be added to the view of the form too.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
            'api.create.post',
            [$this, 'handleResourceTemplateCreateOrUpdatePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
            'api.update.post',
            [$this, 'handleResourceTemplateCreateOrUpdatePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ResourceTemplateAdapter::class,
            'api.delete.post',
            [$this, 'handleResourceTemplateDeletePost']
        );

        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );

        // Module Csv Import.
        $sharedEventManager->attach(
            \CSVImport\Form\MappingForm::class,
            'form.add_elements',
            [$this, 'addCsvImportFormElements']
        );
    }

    /**
     * Add the generation data to the resource JSON-LD.
     *
     * @param Event $event
     */
    public function filterJsonLd(Event $event)
    {
        if (!$this->userCanRead()) {
            return;
        }

        $resource = $event->getTarget();
        $entityColumnName = $this->columnNameOfRepresentation($resource);
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $generations = $api
            ->search('generations', [$entityColumnName => $resource->id()], ['responseContent' => 'reference'])
            ->getContent();
        if ($generations) {
            $jsonLd = $event->getParam('jsonLd');
            // It must be a property, not a class. Cf. iiif too, that uses generations = iiif_prezi:generations
            // Note: Omeka uses singular for "o:item_set".
            $jsonLd['o:generations'] = $generations;
            // @deprecated
            $jsonLd['oa:Generation'] = $generations;
            $event->setParam('jsonLd', $jsonLd);
        }
    }

    /**
     * Helper to filter search queries for resource templates.
     *
     * @param Event $event
     */
    public function searchQueryResourceTemplate(Event $event)
    {
        $query = $event->getParam('request')->getContent();
        if (empty($query['resource_class'])) {
            return;
        }

        list($prefix, $localName) = explode(':', $query['resource_class']);

        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $event->getParam('queryBuilder');
        /** @var \Omeka\Api\Adapter\ResourceTemplateAdapter $adapter */
        $adapter = $event->getTarget();

        $isOldOmeka = \Omeka\Module::VERSION < 2;
        $resourceTemplateAlias = $isOldOmeka ? $adapter->getEntityClass() : 'omeka_root';

        $expr = $qb->expr();
        $resourceClassAlias = $adapter->createAlias();
        $qb->innerJoin(
            $resourceTemplateAlias . '.resourceClass',
            $resourceClassAlias
        );
        $vocabularyAlias = $adapter->createAlias();
        $qb->innerJoin(
            \Omeka\Entity\Vocabulary::class,
            $vocabularyAlias,
            \Doctrine\ORM\Query\Expr\Join::WITH,
            $expr->eq($resourceClassAlias . '.vocabulary', $vocabularyAlias . '.id')
        );
        $qb->andWhere(
            $expr->andX(
                $expr->eq(
                    $vocabularyAlias . '.prefix',
                    $adapter->createNamedParameter($qb, $prefix)
                ),
                $expr->eq(
                    $resourceClassAlias . '.localName',
                    $adapter->createNamedParameter($qb, $localName)
                )
            )
        );
    }

    /**
     * Display the advanced search form for generations via partial.
     *
     * @param Event $event
     */
    public function displayAdvancedSearchGeneration(Event $event)
    {
        $query = $event->getParam('query', []);
        $query['datetime'] = isset($query['datetime']) ? $query['datetime'] : '';
        $partials = $event->getParam('partials', []);

        // Remove the resource class field, since it is always "oa:Generation".
        $key = array_search('common/advanced-search/resource-class', $partials);
        if ($key !== false) {
            unset($partials[$key]);
        }

        // Replace the resource template field, since the templates are
        // restricted to the class "oa:Generation".
        $key = array_search('common/advanced-search/resource-template', $partials);
        if ($key === false) {
            $partials[] = 'common/advanced-search/resource-template-generation';
        } else {
            $partials[$key] = 'common/advanced-search/resource-template-generation';
        }

        $partials[] = 'common/advanced-search/date-time-generation';

        // TODO Add a search form on the metadata of the resources.

        $event->setParam('query', $query);
        $event->setParam('partials', $partials);
    }

    /**
     * Filter search filters of generations for display.
     *
     * @param Event $event
     */
    public function filterSearchFiltersGeneration(Event $event)
    {
        $query = $event->getParam('query', []);
        $view = $event->getTarget();
        $normalizeDateTimeQuery = $view->plugin('normalizeDateTimeQuery');
        if (empty($query['datetime'])) {
            $query['datetime'] = [];
        } else {
            if (!is_array($query['datetime'])) {
                $query['datetime'] = [$query['datetime']];
            }
            foreach ($query['datetime'] as $key => $datetime) {
                $datetime = $normalizeDateTimeQuery($datetime);
                if ($datetime) {
                    $query['datetime'][$key] = $datetime;
                } else {
                    unset($query['datetime'][$key]);
                }
            }
        }
        if (!empty($query['created'])) {
            $datetime = $normalizeDateTimeQuery($query['created'], 'created');
            if ($datetime) {
                $query['datetime'][] = $datetime;
            }
        }
        if (!empty($query['modified'])) {
            $datetime = $normalizeDateTimeQuery($query['modified'], 'modified');
            if ($datetime) {
                $query['datetime'][] = $datetime;
            }
        }

        if (empty($query['datetime'])) {
            return;
        }

        $filters = $event->getParam('filters');
        $translate = $view->plugin('translate');
        $queryTypes = [
            '>' => $translate('after'),
            '>=' => $translate('after or on'),
            '=' => $translate('on'),
            '<>' => $translate('not on'),
            '<=' => $translate('before or on'),
            '<' => $translate('before'),
            'gte' => $translate('after or on'),
            'gt' => $translate('after'),
            'eq' => $translate('on'),
            'neq' => $translate('not on'),
            'lte' => $translate('before or on'),
            'lt' => $translate('before'),
            'ex' => $translate('has any date / time'),
            'nex' => $translate('has no date / time'),
        ];

        $next = false;
        foreach ($query['datetime'] as $queryRow) {
            $joiner = $queryRow['joiner'];
            $field = $queryRow['field'];
            $type = $queryRow['type'];
            $datetimeValue = $queryRow['value'];

            $fieldLabel = $field === 'modified' ? $translate('Modified') : $translate('Created');
            $filterLabel = $fieldLabel . ' ' . $queryTypes[$type];
            if ($next) {
                if ($joiner === 'or') {
                    $filterLabel = $translate('OR') . ' ' . $filterLabel;
                } else {
                    $filterLabel = $translate('AND') . ' ' . $filterLabel;
                }
            } else {
                $next = true;
            }
            $filters[$filterLabel][] = $datetimeValue;
        }

        $event->setParam('filters', $filters);
    }

    public function handleResourceTemplateCreateOrUpdatePost(Event $event)
    {
        // TODO Allow to require a value for body or target via the template.

        // The acl are already checked via the api.
        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $controllerPlugins = $services->get('ControllerPluginManager');
        $generationPartMapper = $controllerPlugins->get('generationPartMapper');

        $result = [];
        $requestContent = $request->getContent();
        $requestResourceProperties = isset($requestContent['o:resource_template_property']) ? $requestContent['o:resource_template_property'] : [];
        foreach ($requestResourceProperties as $propertyId => $requestResourceProperty) {
            if (!isset($requestResourceProperty['data']['generation_part'])) {
                continue;
            }
            try {
                /** @var \Omeka\Api\Representation\PropertyRepresentation $property */
                $property = $api->read('properties', $propertyId)->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                continue;
            }
            $term = $property->term();
            $result[$term] = $generationPartMapper($term, $requestResourceProperty['data']['generation_part']);
        }

        $resourceTemplateId = $response->getContent()->getId();
        $settings = $services->get('Omeka\Settings');
        $resourceTemplateData = $settings->get('generateur_resource_template_data', []);
        $resourceTemplateData[$resourceTemplateId] = $result;
        $settings->set('generateur_resource_template_data', $resourceTemplateData);
    }

    public function handleResourceTemplateDeletePost(Event $event)
    {
        // The acl are already checked via the api.
        $id = $event->getParam('request')->getId();
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $resourceTemplateData = $settings->get('generateur_resource_template_data', []);
        unset($resourceTemplateData[$id]);
        $settings->set('generateur_resource_template_data', $resourceTemplateData);
    }

    public function addCsvImportFormElements(Event $event)
    {
        /** @var \CSVImport\Form\MappingForm $form */
        $form = $event->getTarget();
        $resourceType = $form->getOption('resource_type');
        if ($resourceType !== 'generations') {
            return;
        }

        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        if (!$acl->userIsAllowed(Generation::class, 'create')) {
            return;
        }

        $form->addResourceElements();
        if ($acl->userIsAllowed(\Generateur\Entity\Generation::class, 'change-owner')) {
            $form->addOwnerElement();
        }
        $form->addProcessElements();
        $form->addAdvancedElements();
    }

    /**
     * Add the headers for admin management.
     *
     * @param Event $event
     */
    public function addHeadersAdmin(Event $event)
    {
        // Hacked, because the admin layout doesn't use a partial or a trigger
        // for the search engine.
        $view = $event->getTarget();
        // TODO How to attach all admin events only before 1.3?
        if (!$view->params()->fromRoute('__ADMIN__')) {
            return;
        }
        $view->headLink()
            ->appendStylesheet($view->assetUrl('css/generateur-admin.css', 'Generateur'));
        $searchUrl = sprintf('var searchGenerationsUrl = %s;', json_encode($view->url('admin/generation/default', ['action' => 'browse'], true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $view->headScript()
            ->appendScript($searchUrl)
            ->appendFile($view->assetUrl('js/generateur-admin.js', 'Generateur'), 'text/javascript', ['defer' => 'defer']);
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function addTab(Event $event)
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['generateur'] = 'Generations'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display a partial for a resource.
     *
     * @param Event $event
     */
    public function displayListAndForm(Event $event)
    {
        $resource = $event->getTarget()->resource;
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $allowed = $acl->userIsAllowed(\Omeka\Entity\Item::class, 'create');

        echo '<div id="generateur" class="section generateur">';
        $this->displayResourceGenerations($event, $resource, false);
        if ($allowed) {
            $this->displayForm($event);
        }
        echo '</div>';
    }

    /**
     * Display the list for a resource.
     *
     * @param Event $event
     */
    public function displayList(Event $event)
    {
        echo '<div id="generateur" class="section generateur">';
        $vars = $event->getTarget()->vars();
        // Manage add/edit form.
        if (isset($vars->resource)) {
            $resource = $vars->resource;
        } elseif (isset($vars->item)) {
            $resource = $vars->item;
        } elseif (isset($vars->itemSet)) {
            $resource = $vars->itemSet;
        } elseif (isset($vars->media)) {
            $resource = $vars->media;
        } else {
            $resource = null;
        }
        $vars->offsetSet('resource', $resource);
        $this->displayResourceGenerations($event, $resource, false);
        echo '</div>';
    }

    /**
     * Display the details for a resource.
     *
     * @param Event $event
     */
    public function viewDetails(Event $event)
    {
        $representation = $event->getParam('entity');
        // TODO Use a paginator to limit and display all generations dynamically in the details view (using api).
        $this->displayResourceGenerations($event, $representation, true, ['limit' => 10]);
    }

    /**
     * Display a form.
     *
     * @param Event $event
     */
    public function displayForm(Event $event)
    {
        $view = $event->getTarget();
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $event->getTarget()->resource;

        $services = $this->getServiceLocator();
        $viewHelpers = $services->get('ViewHelperManager');
        $api = $viewHelpers->get('api');
        $url = $viewHelpers->get('url');

        $options = [];
        $attributes = [];
        $attributes['action'] = $url(
            'admin/generation/default',
            ['action' => 'generateur'],
            ['query' => ['redirect' => $resource->adminUrl() . '#generateur']]
        );

        // TODO Get the post when an error occurs (but this is never the case).
        // Currently, this is a redirect.
        // $request = $services->get('Request');
        // $isPost = $request->isPost();
        // if ($isPost) {
        //     $controllerPlugins = $services->get('ControllerPluginManager');
        //     $params = $controllerPlugins->get('params');
        //     $data = $params()->fromPost();
        // }
        $data = [];
        // TODO Make the property id of oa:hasTarget/oa:hasSource static or integrate it to avoid a double query.
        $propertyId = $api->searchOne('properties', ['term' => 'oa:hasSource'])->getContent()->id();
        // TODO Make the form use fieldset.
        $data['oa:hasTarget[0][oa:hasSource][0][property_id]'] = $propertyId;
        $data['oa:hasTarget[0][oa:hasSource][0][type]'] = 'resource';
        $data['oa:hasTarget[0][oa:hasSource][0][value_resource_id]'] = $resource->id();

        echo $view->showGenerateurForm($resource, $options, $attributes, $data);
    }

    /**
     * Display a partial for a resource in public.
     *
     * @param Event $event
     */
    public function displayPublic(Event $event)
    {
        $serviceLocator = $this->getServiceLocator();
        $siteSettings = $serviceLocator->get('Omeka\Settings\Site');
        $view = $event->getTarget();
        $resource = $view->resource;
        $resourceName = $resource->resourceName();
        $appendMap = [
            'item_sets' => 'generateur_append_item_set_show',
            'items' => 'generateur_append_item_show',
            'media' => 'generateur_append_media_show',
        ];
        if (!$siteSettings->get($appendMap[$resourceName])) {
            return;
        }

        echo $view->generations($resource);
    }

    /**
     * Helper to display a partial for a resource.
     *
     * @param Event $event
     * @param AbstractResourceEntityRepresentation $resource
     * @param bool $listAsDiv Return the list with div, not ul.
     */
    protected function displayResourceGenerations(
        Event $event,
        AbstractResourceEntityRepresentation $resource,
        $listAsDiv = false,
        array $query = []
    ) {
        $services = $this->getServiceLocator();
        $controllerPlugins = $services->get('ControllerPluginManager');
        $resourceGenerationsPlugin = $controllerPlugins->get('resourceGenerations');
        $generations = $resourceGenerationsPlugin($resource, $query);
        $totalResourceGenerationsPlugin = $controllerPlugins->get('totalResourceGenerations');
        $totalGenerations = $totalResourceGenerationsPlugin($resource, $query);
        $partial = $listAsDiv
            // Quick detail view.
            ? 'common/admin/generation-resource'
            // Full view in tab.
            : 'common/admin/generation-resource-list';
        echo $event->getTarget()->partial(
            $partial,
            [
                'resource' => $resource,
                'generations' => $generations,
                'totalGenerations' => $totalGenerations,
            ]
        );
    }

    /**
     * Check if a user can read generations.
     *
     * @todo Is it really useful to check if user can read generations?
     *
     * @return bool
     */
    protected function userCanRead()
    {
        $userIsAllowed = $this->getServiceLocator()->get('ViewHelperManager')
            ->get('userIsAllowed');
        return $userIsAllowed(Generation::class, 'read');
    }

    protected function isGenerationEnabledForResource(AbstractEntityRepresentation $resource)
    {
        // TODO Some type of generation may be removed for some generation types.
        return true;

        if ($resource->getControllerName() === 'user') {
            return true;
        }
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $commentResources = $settings->get('generateur_resources');
        $resourceName = $resource->resourceName();
        return in_array($resourceName, $commentResources);
    }

    /**
     * Helper to get the column id of an entity.
     *
     * Note: Resource representation have method resourceName(), but site page
     * and user don't. Site page has no getControllerName().
     *
     * @param AbstractEntity $resource
     * @return string
     */
    protected function columnNameOfEntity(AbstractEntity $resource)
    {
        $entityColumnNames = [
            \Omeka\Entity\ItemSet::class => 'resource_id',
            \Omeka\Entity\Item::class => 'resource_id',
            \Omeka\Entity\Media::class => 'resource_id',
            \Omeka\Entity\User::class => 'owner_id',
        ];
        $entityColumnName = $entityColumnNames[$resource->getResourceId()];
        return $entityColumnName;
    }

    /**
     * Helper to get the column id of a representation.
     *
     * Note: Resource representation have method resourceName(), but site page
     * and user don't. Site page has no getControllerName().
     *
     * @param AbstractEntityRepresentation $representation
     * @return string
     */
    protected function columnNameOfRepresentation(AbstractEntityRepresentation $representation)
    {
        $entityColumnNames = [
            'item-set' => 'resource_id',
            'item' => 'resource_id',
            'media' => 'resource_id',
            'user' => 'owner_id',
        ];
        $entityColumnName = $entityColumnNames[$representation->getControllerName()];
        return $entityColumnName;
    }
}