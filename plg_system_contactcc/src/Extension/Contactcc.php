<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.contactcc
 *
 * @copyright   (C) 2026 Yogesh B. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Contactcc\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Event\AbstractEvent;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Contactcc\Field\EmailccField;
use Joomla\Plugin\System\Contactcc\Rule\CcemailsRule;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Adds a "CC Email Addresses" field to the admin Contact edit form and sends a copy of every
 * contact form submission to those addresses (plus the plugin's global default CC list).
 *
 * This is a system plugin on purpose: the admin Contact form only imports "content" plugins
 * (system plugins are always loaded), so a "contact" group plugin would never see that form.
 */
final class Contactcc extends CMSPlugin implements SubscriberInterface
{
    private const FORM_NAME    = 'com_contact.contact';
    private const FIELD_NAME   = 'cc_emails';
    private const FIELD_GROUP  = 'params';
    private const LOG_CATEGORY = 'plg_system_contactcc';
    private const EMAIL_SPLIT  = '/[\s,;]+/';

    private static bool $loggerRegistered = false;

    /**
     * @return  array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onSubmitContact'      => 'onSubmitContact',
        ];
    }

    /**
     * Injects params[cc_emails] into the admin Contact form and positions it below "Email".
     *
     * @param   EventInterface  $event  Joomla 4: [0 => Form, 1 => data]; Joomla 5: ['subject' => Form, 'data' => data].
     *
     * @return  void
     */
    public function onContentPrepareForm(EventInterface $event): void
    {
        $form = $this->getEventArgument($event, 'subject', 0);

        if (!$form instanceof Form || $form->getName() !== self::FORM_NAME) {
            return;
        }

        $app = $this->getApp();

        // The public contact form is also named "com_contact.contact", so only act in the backend.
        // The API client gets the field definition too, so web-service saves keep the stored value.
        if ($app === null || !($app->isClient('administrator') || $app->isClient('api'))) {
            return;
        }

        try {
            // The same Form instance may be prepared more than once.
            if ($form->getField(self::FIELD_NAME, self::FIELD_GROUP) !== false) {
                return;
            }

            $this->loadLanguage();

            $file = \dirname(__DIR__, 2) . '/forms/contact.xml';

            if (!is_file($file) || !$form->loadFile($file, false)) {
                $this->log('Could not load the CC field definition from ' . $file, Log::WARNING);

                return;
            }

            // Check the comma-separated list when the contact is saved.
            if (class_exists(CcemailsRule::class)) {
                FormHelper::addRulePrefix('Joomla\\Plugin\\System\\Contactcc\\Rule');
                $form->setFieldAttribute(self::FIELD_NAME, 'validate', 'ccemails', self::FIELD_GROUP);
            }

            // The core edit layout renders the Details tab field by field (renderField('email_to'), ...),
            // so form XML alone cannot place a field there. "emailcc" is the core email field plus one
            // extra step: after rendering itself it renders params[cc_emails] directly below.
            $emailType = strtolower((string) $form->getFieldAttribute('email_to', 'type', ''));

            if ($app->isClient('administrator') && $emailType === 'email' && class_exists(EmailccField::class)) {
                FormHelper::addFieldPrefix('Joomla\\Plugin\\System\\Contactcc\\Field');
                $form->setFieldAttribute('email_to', 'type', 'emailcc');
            }
        } catch (\Throwable $e) {
            // Never break the contact edit screen because of this plugin.
            $this->log('Could not add the CC field to the contact form: ' . $e->getMessage(), Log::ERROR);
        }
    }

    /**
     * Sends a copy of a submitted contact form to the contact's CC addresses.
     *
     * @param   EventInterface  $event  Joomla 4: [0 => contact, 1 => data]; Joomla 5: ['subject' => contact, 'data' => data].
     *
     * @return  void
     */
    public function onSubmitContact(EventInterface $event): void
    {
        try {
            $contact = $this->getEventArgument($event, 'subject', 0);
            $data    = $this->getEventArgument($event, 'data', 1);

            if (!\is_object($contact) || !\is_array($data)) {
                return;
            }

            $recipients = $this->getCcRecipients($contact);

            if ($recipients === []) {
                return;
            }

            $app = $this->getApp();

            if ($app === null) {
                return;
            }

            $this->loadLanguage();
            $this->sendCopy($app, $contact, $data, $recipients);
        } catch (\Throwable $e) {
            // A mail problem must never break the visitor's form submission.
            $this->log('Could not send the CC copy of a contact form submission: ' . $e->getMessage(), Log::ERROR);
        }
    }

    /**
     * Per-contact CC list merged with the global default list: trimmed, validated, de-duplicated,
     * and without the contact's own address (it already gets the core email).
     *
     * @param   object  $contact  The contact item.
     *
     * @return  list<string>
     */
    private function getCcRecipients(object $contact): array
    {
        $contactParams = $this->toRegistry($contact->params ?? null);

        $candidates = array_merge(
            $this->splitEmailList($this->stringValue($contactParams->get(self::FIELD_NAME, ''))),
            $this->splitEmailList($this->stringValue($this->params->get('default_cc_emails', '')))
        );

        if ($candidates === []) {
            return [];
        }

        $primary    = strtolower($this->normaliseEmail($this->stringValue($contact->email_to ?? '')));
        $recipients = [];

        foreach ($candidates as $candidate) {
            $email = $this->normaliseEmail($candidate);

            if ($email === '' || !MailHelper::isEmailAddress($email)) {
                $this->log('Skipped invalid CC email address: ' . $candidate, Log::WARNING);

                continue;
            }

            $key = strtolower($email);

            if ($key === $primary || isset($recipients[$key])) {
                continue;
            }

            $recipients[$key] = $email;
        }

        return array_values($recipients);
    }

    /**
     * Builds and sends the CC email.
     *
     * @param   CMSApplicationInterface  $app         The application.
     * @param   object                   $contact     The contact item.
     * @param   array<mixed>             $data        The submitted form data.
     * @param   list<string>             $recipients  Valid CC addresses.
     *
     * @return  void
     */
    private function sendCopy(CMSApplicationInterface $app, object $contact, array $data, array $recipients): void
    {
        $mailFrom = trim($this->stringValue($app->get('mailfrom', '')));
        $fromName = $this->cleanLine($this->stringValue($app->get('fromname', '')));

        $submitterName  = $this->cleanLine($this->stringValue($data['contact_name'] ?? ''));
        $submitterEmail = $this->normaliseEmail($this->stringValue($data['contact_email'] ?? ''));
        $hasReplyTo     = $submitterEmail !== '' && MailHelper::isEmailAddress($submitterEmail);

        $subject = $this->cleanLine($this->stringValue($data['contact_subject'] ?? ''));

        if ($subject === '') {
            $subject = Text::sprintf(
                'PLG_SYSTEM_CONTACTCC_DEFAULT_SUBJECT',
                $this->cleanLine($this->stringValue($contact->name ?? ''))
            );
        }

        $mailer = $this->createMailer();

        if ($mailer instanceof Mail) {
            // The array form works with Mail::setSender() on every Joomla 4.x/5.x version.
            // "false" leaves the Return-Path alone, the same as Joomla's own mailer factory.
            $mailer->setSender([$mailFrom, $fromName, false]);
        } else {
            $mailer->setSender($mailFrom, $fromName);
        }

        foreach ($recipients as $recipient) {
            $mailer->addRecipient($recipient);
        }

        if ($hasReplyTo) {
            $mailer->addReplyTo($submitterEmail, $submitterName);
        }

        $mailer->setSubject('[CC] ' . $subject);
        $mailer->setBody(
            $this->buildBody($app, $contact, $data, $submitterName, $hasReplyTo ? $submitterEmail : '', $subject)
        );

        if ($mailer instanceof Mail) {
            $mailer->isHtml(false);
        }

        if ($mailer->send() === false) {
            $this->log('The mailer reported a failure while sending the CC copy.', Log::ERROR);
        }
    }

    /**
     * Plain-text body: target contact, submitter, message and (optionally) custom fields.
     *
     * @param   CMSApplicationInterface  $app      The application.
     * @param   object                   $contact  The contact item.
     * @param   array<mixed>             $data     The submitted form data.
     * @param   string                   $name     Submitter name.
     * @param   string                   $email    Submitter email ('' when not valid).
     * @param   string                   $subject  Message subject.
     *
     * @return  string
     */
    private function buildBody(
        CMSApplicationInterface $app,
        object $contact,
        array $data,
        string $name,
        string $email,
        string $subject
    ): string {
        $siteName    = $this->cleanLine($this->stringValue($app->get('sitename', '')));
        $contactName = $this->cleanLine($this->stringValue($contact->name ?? ''));
        $message     = trim($this->normaliseNewlines($this->stringValue($data['contact_message'] ?? '')));

        $lines   = [];
        $lines[] = Text::sprintf('PLG_SYSTEM_CONTACTCC_BODY_INTRO', $siteName, Uri::root());
        $lines[] = '';
        $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_CONTACT') . ': ' . $contactName;
        $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_DATE') . ': '
            . $this->stringValue(HTMLHelper::_('date', 'now', Text::_('DATE_FORMAT_LC2')));
        $lines[] = '';
        $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_NAME') . ': ' . $name;
        $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_EMAIL') . ': ' . $email;
        $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_SUBJECT') . ': ' . $subject;
        $lines[] = '';
        $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_MESSAGE') . ':';
        $lines[] = $message;

        if ((int) $this->params->get('include_custom_fields', 1) === 1) {
            $fieldLines = $this->getCustomFieldLines($contact, $data['com_fields'] ?? null);

            if ($fieldLines !== []) {
                $lines[] = '';
                $lines[] = Text::_('PLG_SYSTEM_CONTACTCC_BODY_CUSTOM_FIELDS') . ':';

                foreach ($fieldLines as $fieldLine) {
                    $lines[] = $fieldLine;
                }
            }
        }

        if ($email !== '') {
            $lines[] = '';
            $lines[] = '--';
            $lines[] = Text::sprintf(
                'PLG_SYSTEM_CONTACTCC_BODY_REPLY_HINT',
                $name !== '' ? $name . ' <' . $email . '>' : $email
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Submitted custom fields as "Field Name: Value" lines.
     *
     * @param   object  $contact    The contact item.
     * @param   mixed   $submitted  $data['com_fields'] (field name => raw value).
     *
     * @return  list<string>
     */
    private function getCustomFieldLines(object $contact, mixed $submitted): array
    {
        if (!\is_array($submitted) || $submitted === []) {
            return [];
        }

        $fields = null;

        try {
            if (class_exists(FieldsHelper::class)) {
                // Same context the core contact mail uses. Filtered by the contact's category,
                // language and the visitor's access levels.
                $fields = FieldsHelper::getFields('com_contact.mail', $contact, false, $submitted);
            }
        } catch (\Throwable $e) {
            $this->log('Could not load the custom field definitions: ' . $e->getMessage(), Log::WARNING);
            $fields = null;
        }

        $lines = [];

        if (\is_array($fields)) {
            foreach ($fields as $field) {
                if (!\is_object($field) || !isset($field->name)) {
                    continue;
                }

                $fieldName = $this->stringValue($field->name);

                if ($fieldName === '' || !\array_key_exists($fieldName, $submitted)) {
                    continue;
                }

                $value = $this->formatFieldValue($submitted[$fieldName], $this->getFieldOptions($field));

                if ($value === '') {
                    continue;
                }

                $label   = $this->cleanLine(Text::_($this->stringValue($field->label ?? $fieldName)));
                $lines[] = ($label !== '' ? $label : $fieldName) . ': ' . $value;
            }

            return $lines;
        }

        // Fallback when com_fields is not available: use the submitted field names as labels.
        foreach ($submitted as $name => $raw) {
            $value = $this->formatFieldValue($raw, []);

            if ($value === '') {
                continue;
            }

            $lines[] = $this->humanise((string) $name) . ': ' . $value;
        }

        return $lines;
    }

    /**
     * Option value => option text map for list / radio / checkboxes fields.
     *
     * @param   object  $field  A field from FieldsHelper::getFields().
     *
     * @return  array<string, string>
     */
    private function getFieldOptions(object $field): array
    {
        $options = $this->toRegistry($field->fieldparams ?? null)->get('options');

        if (!\is_array($options) && !\is_object($options)) {
            return [];
        }

        $map = [];

        foreach ((array) $options as $option) {
            if (!\is_array($option) && !\is_object($option)) {
                continue;
            }

            $option = (array) $option;

            if (!\array_key_exists('value', $option)) {
                continue;
            }

            $value       = $this->stringValue($option['value']);
            $text        = $this->stringValue($option['name'] ?? $value);
            $map[$value] = $text !== '' ? Text::_($text) : $value;
        }

        return $map;
    }

    /**
     * Turns a raw submitted value (scalar or nested array) into readable text.
     *
     * @param   mixed                  $value    Raw value.
     * @param   array<string, string>  $options  Option value => text map.
     *
     * @return  string
     */
    private function formatFieldValue(mixed $value, array $options): string
    {
        $parts = [];
        $this->flattenValue($value, $options, $parts);

        // Indent continuation lines so multi-line values stay readable.
        return str_replace("\n", "\n    ", implode(', ', $parts));
    }

    /**
     * @param   mixed                  $value    Raw value.
     * @param   array<string, string>  $options  Option value => text map.
     * @param   list<string>           $parts    Collected text parts.
     *
     * @return  void
     */
    private function flattenValue(mixed $value, array $options, array &$parts): void
    {
        if (\is_array($value) || \is_object($value)) {
            foreach ((array) $value as $item) {
                $this->flattenValue($item, $options, $parts);
            }

            return;
        }

        if (\is_bool($value)) {
            $parts[] = Text::_($value ? 'JYES' : 'JNO');

            return;
        }

        if (!\is_scalar($value)) {
            return;
        }

        $text = (string) $value;
        $text = $options[$text] ?? $text;
        $text = trim($this->normaliseNewlines(strip_tags($text)));

        if ($text !== '') {
            $parts[] = $text;
        }
    }

    /**
     * Reads an event argument by name (Joomla 5) or position (Joomla 4).
     *
     * Reads the raw argument array, so Joomla 5 never logs its "numeric access to named
     * event arguments" deprecation.
     *
     * @param   EventInterface  $event     The event.
     * @param   string          $name      Named argument key.
     * @param   int             $position  Positional argument index.
     *
     * @return  mixed
     */
    private function getEventArgument(EventInterface $event, string $name, int $position): mixed
    {
        if ($event instanceof AbstractEvent) {
            $arguments = $event->getArguments();

            if (\array_key_exists($name, $arguments)) {
                return $arguments[$name];
            }

            return $arguments[$position] ?? null;
        }

        return $event->getArgument($name) ?? $event->getArgument((string) $position);
    }

    /**
     * The application: injected (Joomla 4.2+) or from the factory (Joomla 4.0/4.1).
     *
     * @return  CMSApplicationInterface|null
     */
    private function getApp(): ?CMSApplicationInterface
    {
        if (method_exists($this, 'getApplication')) {
            $app = $this->getApplication();

            if ($app instanceof CMSApplicationInterface) {
                return $app;
            }
        }

        try {
            $app = Factory::getApplication();
        } catch (\Throwable) {
            return null;
        }

        return $app instanceof CMSApplicationInterface ? $app : null;
    }

    /**
     * Joomla 4.4+ / 5.x use the mailer factory service. Joomla 4.0-4.3 don't have it yet,
     * so they fall back to Factory::getMailer(), which is only called on those versions.
     *
     * @return  \Joomla\CMS\Mail\MailerInterface|Mail
     */
    private function createMailer(): object
    {
        if (interface_exists(MailerFactoryInterface::class)) {
            $container = Factory::getContainer();

            if ($container->has(MailerFactoryInterface::class)) {
                return $container->get(MailerFactoryInterface::class)->createMailer();
            }
        }

        return Factory::getMailer();
    }

    /**
     * @param   mixed  $value  Registry, JSON string, array or object.
     *
     * @return  Registry
     */
    private function toRegistry(mixed $value): Registry
    {
        if ($value instanceof Registry) {
            return $value;
        }

        try {
            if (\is_string($value) && trim($value) !== '') {
                return new Registry($value);
            }

            if (\is_array($value) || \is_object($value)) {
                return new Registry($value);
            }
        } catch (\Throwable) {
            // Invalid JSON: treat as empty.
        }

        return new Registry();
    }

    /**
     * @param   string  $value  Comma / semicolon / whitespace separated list.
     *
     * @return  list<string>
     */
    private function splitEmailList(string $value): array
    {
        $parts = preg_split(self::EMAIL_SPLIT, trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return \is_array($parts) ? $parts : [];
    }

    /**
     * Trims an address and converts IDN domains to punycode ('' if that fails).
     *
     * @param   string  $email  Raw address.
     *
     * @return  string
     */
    private function normaliseEmail(string $email): string
    {
        $email = trim($email);

        if ($email === '') {
            return '';
        }

        try {
            return trim((string) PunycodeHelper::emailToPunycode($email));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Single-line, tag-free text (safe for headers).
     *
     * @param   string  $value  Raw text.
     *
     * @return  string
     */
    private function cleanLine(string $value): string
    {
        return trim((string) MailHelper::cleanLine(strip_tags($value)));
    }

    /**
     * @param   string  $value  Text.
     *
     * @return  string
     */
    private function normaliseNewlines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * "my-field_name" => "My field name".
     *
     * @param   string  $name  Field name.
     *
     * @return  string
     */
    private function humanise(string $name): string
    {
        return ucfirst($this->cleanLine(str_replace(['-', '_'], ' ', $name)));
    }

    /**
     * @param   mixed  $value  Any value.
     *
     * @return  string  Scalars as string, anything else as ''.
     */
    private function stringValue(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Writes to administrator/logs/plg_system_contactcc.php. Never throws.
     *
     * @param   string  $message   Message.
     * @param   int     $priority  Log::* priority.
     *
     * @return  void
     */
    private function log(string $message, int $priority): void
    {
        try {
            if (!self::$loggerRegistered) {
                Log::addLogger(['text_file' => self::LOG_CATEGORY . '.php'], Log::ALL, [self::LOG_CATEGORY]);
                self::$loggerRegistered = true;
            }

            Log::add($message, $priority, self::LOG_CATEGORY);
        } catch (\Throwable) {
            // Logging must never break the request.
        }
    }
}
