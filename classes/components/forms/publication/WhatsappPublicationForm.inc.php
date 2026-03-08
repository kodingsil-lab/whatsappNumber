<?php

/**
 * @file plugins/generic/whatsappNumber/classes/components/forms/publication/WhatsappPublicationForm.inc.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WhatsappPublicationForm
 * @brief Editor form for managing a submission's internal WhatsApp number.
 */

use \PKP\components\forms\FormComponent;
use \PKP\components\forms\FieldText;

define('FORM_WHATSAPP_PUBLICATION', 'whatsappPublication');

class WhatsappPublicationForm extends FormComponent {
	/** @copydoc FormComponent::$id */
	public $id = FORM_WHATSAPP_PUBLICATION;

	/** @copydoc FormComponent::$method */
	public $method = 'PUT';

	/**
	 * Constructor
	 *
	 * @param string $action URL to submit the form to
	 * @param string $whatsappNumber Current submission WhatsApp number
	 */
	public function __construct($action, $whatsappNumber) {
		$this->action = $action;

		$this->addField(new FieldText('whatsappNumber', [
			'label' => __('plugins.generic.whatsappNumber.fieldLabel'),
			'tooltip' => __('plugins.generic.whatsappNumber.fieldDescription'),
			'value' => (string) $whatsappNumber,
		]));
	}
}
