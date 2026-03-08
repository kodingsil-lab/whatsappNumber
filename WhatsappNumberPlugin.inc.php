<?php

/**
 * @file plugins/generic/whatsappNumber/WhatsappNumberPlugin.inc.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @package plugins.generic.whatsappNumber
 * @class WhatsappNumberPlugin
 *
 * Add an internal WhatsApp number field to submission step 1 and the editor workflow.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('classes.core.Services');
import('lib.pkp.classes.db.DAORegistry');

class WhatsappNumberPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.whatsappNumber.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.whatsappNumber.description');
	}

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!$success) {
			return false;
		}

		// If the system isn't installed, or is performing an upgrade, don't register hooks.
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
			return true;
		}

		if ($this->getEnabled($mainContextId)) {
			HookRegistry::register('submissionsubmitstep1form::display', array($this, 'addSubmissionStep1Field'));
			HookRegistry::register('submissionsubmitstep1form::validate', array($this, 'validateSubmissionStep1Field'));
			HookRegistry::register('SubmissionHandler::saveSubmit', array($this, 'saveSubmissionMetadataField'));
			HookRegistry::register('Submission::edit', array($this, 'persistWhatsappNumberOnSubmissionEdit'));
			HookRegistry::register('Publication::edit', array($this, 'persistWhatsappNumberOnPublicationEdit'));
			HookRegistry::register('Template::Workflow::Publication', array($this, 'addWorkflowPublicationTab'));
			HookRegistry::register('Schema::get::submission', array($this, 'addSubmissionSchema'));
			HookRegistry::register('Schema::get::publication', array($this, 'addPublicationSchema'));
			HookRegistry::register('submissiondao::getAdditionalFieldNames', array($this, 'addSubmissionAdditionalFieldNames'));
			HookRegistry::register('publicationdao::getAdditionalFieldNames', array($this, 'addPublicationAdditionalFieldNames'));
		}

		return true;
	}

	/**
	 * Add WhatsApp field to submission step 1 form.
	 */
	function addSubmissionStep1Field($hookName, $params) {
		$form = $params[0];
		if (!is_a($form, 'SubmissionSubmitStep1Form') && !is_a($form, 'PKPSubmissionSubmitStep1Form')) {
			return false;
		}

		$request = Application::get()->getRequest();
		$templateMgr = TemplateManager::getManager($request);

		$whatsappNumber = !empty($form->submission) ? $this->getStoredWhatsappNumber($form->submission) : '';

		if ($whatsappNumber === '') {
			$whatsappNumber = trim((string) $request->getUserVar('whatsappNumber'));
		}

		$templateMgr->assign('whatsappNumber', $whatsappNumber);
		$additionalFormContent = (string) $templateMgr->getTemplateVars('additionalFormContent1');
		$additionalFormContent .= $templateMgr->fetch($this->getTemplateResource('whatsappField.tpl'));
		$templateMgr->assign('additionalFormContent1', $additionalFormContent);

		return false;
	}

	/**
	 * Validate WhatsApp number in submission step 1.
	 */
	function validateSubmissionStep1Field($hookName, $params) {
		$form = $params[0];
		if (!is_a($form, 'SubmissionSubmitStep1Form') && !is_a($form, 'PKPSubmissionSubmitStep1Form')) {
			return false;
		}

		$request = Application::get()->getRequest();
		$whatsappNumber = trim((string) $request->getUserVar('whatsappNumber'));
		$this->setPendingWhatsappNumber($request, $whatsappNumber);
		if ($whatsappNumber === '') {
			$form->addError('whatsappNumber', 'plugins.generic.whatsappNumber.fieldRequired');
			$form->addErrorField('whatsappNumber');
		}

		return false;
	}

	/**
	 * Save WhatsApp number from submission step 1.
	 */
	function saveSubmissionMetadataField($hookName, $params) {
		$step = (int) $params[0];
		$submission = $params[1];

		if (!in_array($step, array(1, 3))) {
			return false;
		}

		$request = Application::get()->getRequest();
		$whatsappNumber = $this->getWhatsappNumberForPersistence($request);

		if (!$submission) {
			return false;
		}

		$this->writeSubmissionWhatsappNumber($submission->getId(), $whatsappNumber);
		$this->clearPendingWhatsappNumber($request);

		return false;
	}

	/**
	 * Persist WhatsApp number after the first publication is attached to a new submission.
	 */
	function persistWhatsappNumberOnSubmissionEdit($hookName, $params) {
		$newSubmission = $params[0];
		$submissionParams = $params[2];
		$request = $params[3];

		if (empty($submissionParams['currentPublicationId'])) {
			return false;
		}

		$whatsappNumber = $this->getWhatsappNumberForPersistence($request, true);
		if ($whatsappNumber === null && $request->getUserVar('whatsappNumber') === null && $this->getPendingWhatsappNumber($request) === null) {
			return false;
		}

		$this->writeSubmissionWhatsappNumber($newSubmission->getId(), $whatsappNumber);
		$this->clearPendingWhatsappNumber($request);

		if ($whatsappNumber !== null || $request->getUserVar('whatsappNumber') !== null) {
			$newSubmission->setData('whatsappNumber', $whatsappNumber);
		}

		return false;
	}

	/**
	 * Persist WhatsApp number when the workflow publication form is saved.
	 */
	function persistWhatsappNumberOnPublicationEdit($hookName, $params) {
		$newPublication = $params[0];
		$publication = $params[1];
		$request = $params[3];
		$whatsappNumber = $this->getWhatsappNumberForPersistence($request, true);

		if ($whatsappNumber === null && $request->getUserVar('whatsappNumber') === null) {
			return false;
		}

		$submissionId = $newPublication ? $newPublication->getData('submissionId') : null;
		if (!$submissionId && $publication) {
			$submissionId = $publication->getData('submissionId');
		}

		if ($submissionId) {
			$this->writeSubmissionWhatsappNumber($submissionId, $whatsappNumber);
		}

		if ($whatsappNumber !== null || $request->getUserVar('whatsappNumber') !== null) {
			$newPublication->setData('whatsappNumber', $whatsappNumber);
		}

		return false;
	}

	/**
	 * Add a dedicated WhatsApp tab to the workflow publication sidebar.
	 */
	function addWorkflowPublicationTab($hookName, $args) {
		$templateMgr = $args[1];
		$output =& $args[2];
		$request = Application::get()->getRequest();
		$submission = $templateMgr->getTemplateVars('submission');

		if (!$request || !$submission) {
			return false;
		}

		$submissionContext = $request->getContext();
		if ($submission->getContextId() !== $submissionContext->getId()) {
			$submissionContext = Services::get('context')->get($submission->getContextId());
		}

		$latestPublication = $submission->getLatestPublication();
		if (!$latestPublication) {
			return false;
		}
		$publicationApiUrl = $request->getDispatcher()->url(
			$request,
			ROUTE_API,
			$submissionContext->getPath(),
			'submissions/' . $submission->getId() . '/publications/' . $latestPublication->getId()
		);

		import('plugins.generic.whatsappNumber.classes.components.forms.publication.WhatsappPublicationForm');
		$whatsappNumber = $this->ensureSubmissionWhatsappNumber($submission, $request);
		$whatsappPublicationForm = new WhatsappPublicationForm($publicationApiUrl, $whatsappNumber);

		$components = (array) $templateMgr->getState('components');
		$components[FORM_WHATSAPP_PUBLICATION] = $whatsappPublicationForm->getConfig();

		$publicationFormIds = (array) $templateMgr->getState('publicationFormIds');
		if (!in_array(FORM_WHATSAPP_PUBLICATION, $publicationFormIds)) {
			$publicationFormIds[] = FORM_WHATSAPP_PUBLICATION;
		}

		$workingPublication = (array) $templateMgr->getState('workingPublication');
		if (!empty($workingPublication)) {
			$workingPublication['whatsappNumber'] = $whatsappNumber;
		}

		$currentPublication = (array) $templateMgr->getState('currentPublication');
		if (!empty($currentPublication) && (int) ($currentPublication['id'] ?? 0) === (int) $latestPublication->getId()) {
			$currentPublication['whatsappNumber'] = $whatsappNumber;
		}

		$templateMgr->setState([
			'components' => $components,
			'publicationFormIds' => $publicationFormIds,
			'workingPublication' => $workingPublication,
			'currentPublication' => $currentPublication,
		]);
		$templateMgr->assign('state', array_merge((array) $templateMgr->getTemplateVars('state'), [
			'components' => $components,
			'publicationFormIds' => $publicationFormIds,
			'workingPublication' => $workingPublication,
			'currentPublication' => $currentPublication,
		]));

		$output .= $templateMgr->fetch($this->getTemplateResource('workflowTab.tpl'));

		return false;
	}

	/**
	 * Extend the submission schema to include an internal WhatsApp number.
	 */
	function addSubmissionSchema($hookName, $params) {
		$schema =& $params[0];
		$schema->properties->whatsappNumber = (object) array(
			'type' => 'string',
			'writeOnly' => true,
			'validation' => array('nullable'),
		);
		return false;
	}

	/**
	 * Extend the publication schema so workflow form submissions accept the field.
	 */
	function addPublicationSchema($hookName, $params) {
		$schema =& $params[0];
		$schema->properties->whatsappNumber = (object) array(
			'type' => 'string',
			'writeOnly' => true,
			'validation' => array('nullable'),
		);
		return false;
	}

	/**
	 * Allow the SubmissionDAO to store WhatsApp number.
	 */
	function addSubmissionAdditionalFieldNames($hookName, $params) {
		$fields =& $params[1];
		$fields[] = 'whatsappNumber';
		return false;
	}

	/**
	 * Allow the PublicationDAO to read legacy WhatsApp data for migration.
	 */
	function addPublicationAdditionalFieldNames($hookName, $params) {
		$fields =& $params[1];
		$fields[] = 'whatsappNumber';
		return false;
	}

	private function ensureSubmissionWhatsappNumber($submission, $request) {
		if (!$submission) {
			return '';
		}

		$submissionWhatsapp = $this->readSubmissionWhatsappNumber($submission->getId());
		if ($submissionWhatsapp !== '') {
			return $submissionWhatsapp;
		}

		$legacyWhatsapp = $this->readLegacyPublicationWhatsappNumber($submission);
		if ($legacyWhatsapp !== '') {
			$this->writeSubmissionWhatsappNumber($submission->getId(), $legacyWhatsapp);
			return $legacyWhatsapp;
		}

		return '';
	}

	private function getStoredWhatsappNumber($submission) {
		if (!$submission) {
			return '';
		}

		$submissionWhatsapp = $this->readSubmissionWhatsappNumber($submission->getId());
		if ($submissionWhatsapp !== '') {
			return $submissionWhatsapp;
		}

		$submissionWhatsapp = trim((string) $submission->getData('whatsappNumber'));
		if ($submissionWhatsapp !== '') {
			return $submissionWhatsapp;
		}

		$publicationWhatsapp = $this->readLegacyPublicationWhatsappNumber($submission);
		if ($publicationWhatsapp !== '') {
			return $publicationWhatsapp;
		}

		$publication = $submission->getCurrentPublication();
		if (!$publication) {
			$publication = $submission->getLatestPublication();
		}
		if ($publication) {
			$publicationWhatsapp = trim((string) $publication->getData('whatsappNumber'));
			if ($publicationWhatsapp !== '') {
				return $publicationWhatsapp;
			}
		}

		return '';
	}

	private function readSubmissionWhatsappNumber($submissionId) {
		if (!$submissionId) {
			return '';
		}

		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$result = $submissionDao->retrieve(
			'SELECT setting_value FROM submission_settings WHERE submission_id = ? AND setting_name = ? AND locale = ?',
			[(int) $submissionId, 'whatsappNumber', '']
		);
		$row = $result->current();

		return $row && isset($row->setting_value) ? trim((string) $row->setting_value) : '';
	}

	private function readLegacyPublicationWhatsappNumber($submission) {
		if (!$submission) {
			return '';
		}

		$publication = $submission->getCurrentPublication();
		if (!$publication) {
			$publication = $submission->getLatestPublication();
		}
		if (!$publication) {
			return '';
		}

		$publicationDao = DAORegistry::getDAO('PublicationDAO');
		$result = $publicationDao->retrieve(
			'SELECT setting_value FROM publication_settings WHERE publication_id = ? AND setting_name = ? AND locale = ?',
			[(int) $publication->getId(), 'whatsappNumber', '']
		);
		$row = $result->current();

		return $row && isset($row->setting_value) ? trim((string) $row->setting_value) : '';
	}

	private function writeSubmissionWhatsappNumber($submissionId, $whatsappNumber) {
		if (!$submissionId) {
			return;
		}

		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submissionDao->update(
			'DELETE FROM submission_settings WHERE submission_id = ? AND setting_name = ? AND locale = ?',
			[(int) $submissionId, 'whatsappNumber', '']
		);

		if ($whatsappNumber === null) {
			return;
		}

		$submissionDao->update(
			'INSERT INTO submission_settings (submission_id, locale, setting_name, setting_value) VALUES (?, ?, ?, ?)',
			[(int) $submissionId, '', 'whatsappNumber', (string) $whatsappNumber]
		);
	}

	private function getWhatsappNumberFromRequest($request, $allowMissing = false) {
		if (!$request) {
			return null;
		}

		$rawWhatsappNumber = $request->getUserVar('whatsappNumber');
		if ($allowMissing && $rawWhatsappNumber === null) {
			return null;
		}

		$whatsappNumber = trim((string) $rawWhatsappNumber);
		return $whatsappNumber === '' ? null : $whatsappNumber;
	}

	private function getWhatsappNumberForPersistence($request, $allowMissing = false) {
		$whatsappNumber = $this->getWhatsappNumberFromRequest($request, $allowMissing);
		if ($whatsappNumber !== null) {
			return $whatsappNumber;
		}

		$pendingWhatsappNumber = $this->getPendingWhatsappNumber($request);
		if ($pendingWhatsappNumber === null) {
			return null;
		}

		$pendingWhatsappNumber = trim((string) $pendingWhatsappNumber);
		return $pendingWhatsappNumber === '' ? null : $pendingWhatsappNumber;
	}

	private function getPendingWhatsappNumber($request) {
		if (!$request) {
			return null;
		}

		$session = $request->getSession();
		return $session ? $session->getSessionVar('whatsappNumber.pending') : null;
	}

	private function setPendingWhatsappNumber($request, $whatsappNumber) {
		if (!$request) {
			return;
		}

		$session = $request->getSession();
		if (!$session) {
			return;
		}

		$session->setSessionVar('whatsappNumber.pending', trim((string) $whatsappNumber));
	}

	private function clearPendingWhatsappNumber($request) {
		if (!$request) {
			return;
		}

		$session = $request->getSession();
		if ($session) {
			$session->unsetSessionVar('whatsappNumber.pending');
		}
	}
}
