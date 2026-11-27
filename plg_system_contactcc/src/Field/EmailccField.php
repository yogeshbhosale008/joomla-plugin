<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.contactcc
 *
 * @copyright   (C) 2026 Yogesh B. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Contactcc\Field;

use Joomla\CMS\Form\Field\EmailField;
use Joomla\CMS\Form\Form;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The core "email" field, unchanged, except that after rendering itself it also renders
 * params[cc_emails]. The plugin sets this as the type of the Contact form's "email_to" field,
 * so the CC input appears directly below "Email" in the admin Contact edit view.
 */
class EmailccField extends EmailField
{
    /**
     * @var  string
     */
    protected $type = 'Emailcc';

    /**
     * @param   array<string, mixed>  $options  Render options.
     *
     * @return  string
     */
    public function renderField($options = []): string
    {
        $html = (string) parent::renderField($options);

        if ($this->form instanceof Form) {
            $html .= (string) $this->form->renderField('cc_emails', 'params');
        }

        return $html;
    }
}
