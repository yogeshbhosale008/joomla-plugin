<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.contactcc
 *
 * @copyright   (C) 2026 Yogesh B. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Contactcc\Rule;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * validate="ccemails": an optional list of addresses separated by commas, semicolons or
 * whitespace, each checked with MailHelper::isEmailAddress().
 */
final class CcemailsRule extends FormRule
{
    /**
     * @param   \SimpleXMLElement  $element  The field XML element.
     * @param   mixed              $value    The submitted value.
     * @param   string|null        $group    The field group.
     * @param   Registry|null      $input    All submitted form data.
     * @param   Form|null          $form     The form.
     *
     * @return  bool
     *
     * @throws  \UnexpectedValueException  With the list of invalid addresses.
     */
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!\is_scalar($value)) {
            return false;
        }

        $parts   = preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        $invalid = [];

        foreach (\is_array($parts) ? $parts : [] as $part) {
            try {
                $email = (string) PunycodeHelper::emailToPunycode($part);
            } catch (\Throwable) {
                $email = '';
            }

            if ($email === '' || !MailHelper::isEmailAddress($email)) {
                $invalid[] = $part;
            }
        }

        if ($invalid !== []) {
            throw new \UnexpectedValueException(
                Text::sprintf(
                    'PLG_SYSTEM_CONTACTCC_ERROR_INVALID_EMAILS',
                    htmlspecialchars(implode(', ', $invalid), ENT_QUOTES, 'UTF-8')
                )
            );
        }

        return true;
    }
}
