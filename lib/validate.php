<?php
declare(strict_types=1);

/**
 * Server-side form validation.
 *
 * Usage:
 *   $errors = validate($_POST, [
 *       'full_name' => 'required|max:150',
 *       'email'     => 'required|email|max:255',
 *       'password'  => 'required|min:8|max:255|confirmed',
 *       'role'      => 'required|enum:user,staff,admin',
 *       'date_lost' => 'required|date',
 *       'age'       => 'integer',
 *       'phone'     => 'regex:/^09\d{9}$/',
 *   ]);
 *
 *   if ($errors) {
 *       flash_set('errors', $errors);
 *       flash_set('old', $_POST);
 *       back();
 *   }
 *
 * Supported rules: required, email, min:N, max:N, regex:/.../, enum:a,b,c,
 *                  date, integer, confirmed (matches `<field>_confirm`).
 *
 * Server-side is the gate; the client-side mirror in assets/js/validate.js
 * (Task 4) is a UX layer only.
 */

/**
 * Run a rule-set against an input array. Returns ['field' => ['error', ...]].
 * Empty result means valid.
 */
function validate(array $data, array $rules): array
{
    $errors = [];

    foreach ($rules as $field => $rule_string) {
        $value     = $data[$field] ?? null;
        $rule_list = explode('|', $rule_string);
        $is_required = in_array('required', $rule_list, true);
        $is_empty  = ($value === null || $value === '' || (is_array($value) && empty($value)));

        if ($is_required && $is_empty) {
            $errors[$field][] = 'This field is required.';
            continue;
        }
        if (!$is_required && $is_empty) {
            continue; // optional + empty → skip all remaining rules
        }

        foreach ($rule_list as $rule) {
            if ($rule === 'required') {
                continue;
            }

            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

            switch ($name) {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field][] = 'Must be a valid email address.';
                    }
                    break;

                case 'min':
                    if (mb_strlen((string) $value) < (int) $arg) {
                        $errors[$field][] = "Must be at least {$arg} characters.";
                    }
                    break;

                case 'max':
                    if (mb_strlen((string) $value) > (int) $arg) {
                        $errors[$field][] = "Must be at most {$arg} characters.";
                    }
                    break;

                case 'regex':
                    if (!is_string($arg) || !@preg_match($arg, (string) $value)) {
                        $errors[$field][] = 'Invalid format.';
                    }
                    break;

                case 'enum':
                    $allowed = explode(',', (string) $arg);
                    if (!in_array((string) $value, $allowed, true)) {
                        $errors[$field][] = 'Invalid value.';
                    }
                    break;

                case 'date':
                    if (!is_string($value) || strtotime($value) === false) {
                        $errors[$field][] = 'Must be a valid date.';
                    }
                    break;

                case 'integer':
                    if (!ctype_digit((string) $value)) {
                        $errors[$field][] = 'Must be a whole number.';
                    }
                    break;

                case 'confirmed':
                    $confirm_field = $field . '_confirm';
                    if (($data[$confirm_field] ?? null) !== $value) {
                        $errors[$field][] = 'Confirmation does not match.';
                    }
                    break;

                default:
                    // Unknown rule: skip silently. Validation rules are author-controlled,
                    // so this should never fire in production.
                    break;
            }
        }
    }

    return $errors;
}

/**
 * Flatten validate() output into a single message per field — useful for
 * showing the first error under a field label.
 */
function first_errors(array $errors): array
{
    $flat = [];
    foreach ($errors as $field => $messages) {
        $flat[$field] = $messages[0] ?? '';
    }
    return $flat;
}
