<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\OptionsResolver;

use Symfony\Component\OptionsResolver\Exception\OptionDefinitionException;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

/**
 * Helper for merging default and concrete option values.
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Tobias Schultze <http://tobion.de>
 */
class OptionsResolver
{
    /**
     * The default option values.
     * @var Options
     */
    private $defaultOptions;

    /**
     * The options known by the resolver.
     * @var array
     */
    private $knownOptions = array();

    /**
     * The options without defaults that are required to be passed to resolve().
     * @var array
     */
    private $requiredOptions = array();

    /**
     * A list of accepted values for each option.
     * @var array
     */
    private $allowedValues = array();

    /**
     * A list of accepted types for each option.
     * @var array
     */
    private $allowedTypes = array();

    /**
     * A list of filters transforming each resolved options.
     * @var array
     */
    private $filters = array();

    /**
     * Creates a new instance.
     */
    public function __construct()
    {
        $this->defaultOptions = new Options();
    }

    /**
     * Sets default option values.
     *
     * The options can either be values of any types or closures that
     * evaluate the option value lazily. These closures must have one
     * of the following signatures:
     *
     * <code>
     * function (Options $options)
     * function (Options $options, $value)
     * </code>
     *
     * The second parameter passed to the closure is the previously
     * set default value, in case you are overwriting an existing
     * default value.
     *
     * The closures should return the lazily created option value.
     *
     * @param array $defaultValues A list of option names as keys and default
     *                             values or closures as values.
     *
     * @return OptionsResolver The resolver instance.
     */
    public function setDefaults(array $defaultValues)
    {
        foreach ($defaultValues as $option => $value) {
            $this->defaultOptions->overload($option, $value);
            $this->knownOptions[$option] = true;
            unset($this->requiredOptions[$option]);
        }

        return $this;
    }

    /**
     * Replaces default option values.
     *
     * Old defaults are erased, which means that closures passed here cannot
     * access the previous default value. This may be useful to improve
     * performance if the previous default value is calculated by an expensive
     * closure.
     *
     * @param array $defaultValues A list of option names as keys and default
     *                             values or closures as values.
     *
     * @return OptionsResolver The resolver instance.
     */
    public function replaceDefaults(array $defaultValues)
    {
        foreach ($defaultValues as $option => $value) {
            $this->defaultOptions->set($option, $value);
            $this->knownOptions[$option] = true;
            unset($this->requiredOptions[$option]);
        }

        return $this;
    }

    /**
     * Sets optional options.
     *
     * This method declares a valid option names without setting default values for
     * them. If these options are not passed to {@link resolve()} and no default has
     * been set for them, they will be missing in the final options array. This can
     * be helpful if you want to determine whether an option has been set or not
     * because otherwise {@link resolve()} would trigger an exception for unknown
     * options.
     *
     * @param array $optionNames A list of option names.
     *
     * @return OptionsResolver The resolver instance.
     *
     * @throws OptionDefinitionException  When trying to pass default values.
     */
    public function setOptional(array $optionNames)
    {
        foreach ($optionNames as $key => $option) {
            if (!is_int($key)) {
                throw new OptionDefinitionException('You should not pass default values to setOptional()');
            }

            $this->knownOptions[$option] = true;
        }

        return $this;
    }

    /**
     * Sets required options.
     *
     * If these options are not passed to resolve() and no default has been set for
     * them, an exception will be thrown.
     *
     * @param array $optionNames A list of option names.
     *
     * @return OptionsResolver The resolver instance.
     *
     * @throws OptionDefinitionException  When trying to pass default values.
     */
    public function setRequired(array $optionNames)
    {
        foreach ($optionNames as $key => $option) {
            if (!is_int($key)) {
                throw new OptionDefinitionException('You should not pass default values to setRequired()');
            }

            $this->knownOptions[$option] = true;
            // set as required if no default has been set already
            if (!isset($this->defaultOptions[$option])) {
                $this->requiredOptions[$option] = true;
            }
        }

        return $this;
    }

    /**
     * Sets allowed values for a list of options.
     *
     * @param array $allowedValues A list of option names as keys and arrays
     *                             with values acceptable for that option as
     *                             values.
     *
     * @return OptionsResolver The resolver instance.
     *
     * @throws InvalidOptionsException If an option has not been defined
     *                                 (see {@link isKnown()}) for which
     *                                 an allowed value is set.
     */
    public function setAllowedValues(array $allowedValues)
    {
        $this->validateOptionsExistence($allowedValues);

        $this->allowedValues = array_replace($this->allowedValues, $allowedValues);

        return $this;
    }

    /**
     * Adds allowed values for a list of options.
     *
     * The values are merged with the allowed values defined previously.
     *
     * @param array $allowedValues A list of option names as keys and arrays
     *                             with values acceptable for that option as
     *                             values.
     *
     * @return OptionsResolver The resolver instance.
     *
     * @throws InvalidOptionsException If an option has not been defined for
     *                                 which an allowed value is set.
     */
    public function addAllowedValues(array $allowedValues)
    {
        $this->validateOptionsExistence($allowedValues);

        $this->allowedValues = array_merge_recursive($this->allowedValues, $allowedValues);

        return $this;
    }

    /**
     * Sets allowed types for a list of options.
     *
     * @param array $allowedTypes A list of option names as keys and type
     *                            names passed as string or array as values.
     *
     * @return OptionsResolver The resolver instance.
     *
     * @throws InvalidOptionsException If an option has not been defined for
     *                                 which an allowed type is set.
     */
    public function setAllowedTypes(array $allowedTypes)
    {
        $this->validateOptionNames(array_keys($allowedTypes));

        $this->allowedTypes = array_replace($this->allowedTypes, $allowedTypes);

        return $this;
    }

    /**
     * Adds allowed types for a list of options.
     *
     * The types are merged with the allowed types defined previously.
     *
     * @param array $allowedTypes A list of option names as keys and type
     *                            names passed as string or array as values.
     *
     * @return OptionsResolver The resolver instance.
     *
     * @throws InvalidOptionsException If an option has not been defined for
     *                                 which an allowed type is set.
     */
    public function addAllowedTypes(array $allowedTypes)
    {
        $this->validateOptionNames(array_keys($allowedTypes));

        $this->allowedTypes = array_merge_recursive($this->allowedTypes, $allowedTypes);

        return $this;
    }

    /**
     * Sets filters that are applied on resolved options.
     *
     * The filters should be closures with the following signature:
     *
     * <code>
     * function (Options $options, $value)
     * </code>
     *
     * The second parameter passed to the closure is the value of
     * the option.
     *
     * The closure should return the filtered value.
     *
     * @param array $filters
     * @return OptionsResolver
     */
    public function setFilters(array $filters)
    {
        $this->validateOptionsExistence($filters);

        $this->filters = array_replace($this->filters, $filters);

        return $this;
    }

    /**
     * Returns whether an option is known.
     *
     * An option is known if it has been passed to either {@link setDefaults()},
     * {@link setRequired()} or {@link setOptional()} before.
     *
     * @param string $option The name of the option.
     * @return Boolean        Whether the option is known.
     */
    public function isKnown($option)
    {
        return isset($this->knownOptions[$option]);
    }

    /**
     * Returns whether an option is required.
     *
     * An option is required if it has been passed to {@link setRequired()},
     * but not to {@link setDefaults()}. That is, the option has been declared
     * as required and no default value has been set.
     *
     * @param string $option The name of the option.
     * @return Boolean        Whether the option is required.
     */
    public function isRequired($option)
    {
        return isset($this->requiredOptions[$option]);
    }

    /**
     * Returns the combination of the default and the passed options.
     *
     * @param array $options The custom option values.
     *
     * @return array A list of options and their values.
     *
     * @throws InvalidOptionsException   If any of the passed options has not
     *                                   been defined or does not contain an
     *                                   allowed value.
     * @throws MissingOptionsException   If a required option is missing.
     * @throws OptionDefinitionException If a cyclic dependency is detected
     *                                   between two lazy options.
     */
    public function resolve(array $options)
    {
        $this->validateOptionsExistence($options);
        $this->validateOptionsCompleteness($options);

        // Make sure this method can be called multiple times
        $combinedOptions = clone $this->defaultOptions;

        // Override options set by the user
        foreach ($options as $option => $value) {
            $combinedOptions->set($option, $value);
        }

        // Apply filters
        foreach ($this->filters as $option => $filter) {
            $combinedOptions->overload($option, $filter);
        }

        // Resolve options
        $resolvedOptions = $combinedOptions->all();

        $this->validateOptionValues($resolvedOptions);
        $this->validateOptionTypes($resolvedOptions);

        return $resolvedOptions;
    }

    /**
     * Validates that the given option names exist and throws an exception
     * otherwise.
     *
     * @param array $options An list of option names as keys.
     *
     * @throws InvalidOptionsException If any of the options has not been defined.
     */
    private function validateOptionsExistence(array $options)
    {
        $diff = array_diff_key($options, $this->knownOptions);

        if (count($diff) > 0) {
            ksort($this->knownOptions);
            ksort($diff);

            throw new InvalidOptionsException(sprintf(
                (count($diff) > 1 ? 'The options "%s" do not exist.' : 'The option "%s" does not exist.') . ' Known options are: "%s"',
                implode('", "', array_keys($diff)),
                implode('", "', array_keys($this->knownOptions))
            ));
        }
    }

    /**
     * Validates that all required options are given and throws an exception
     * otherwise.
     *
     * @param array $options An list of option names as keys.
     *
     * @throws MissingOptionsException If a required option is missing.
     */
    private function validateOptionsCompleteness(array $options)
    {
        $diff = array_diff_key($this->requiredOptions, $options);

        if (count($diff) > 0) {
            ksort($diff);

            throw new MissingOptionsException(sprintf(
                count($diff) > 1 ? 'The required options "%s" are missing.' : 'The required option "%s" is  missing.',
                implode('", "', array_keys($diff))
            ));
        }
    }

    /**
     * Validates that the given option values match the allowed values and
     * throws an exception otherwise.
     *
     * @param array $options A list of option values.
     *
     * @throws InvalidOptionsException If any of the values does not match the
     *                                 allowed values of the option.
     */
    private function validateOptionValues(array $options)
    {
        foreach ($this->allowedValues as $option => $allowedValues) {
            if (!in_array($options[$option], $allowedValues, true)) {
                throw new InvalidOptionsException(sprintf('The option "%s" has the value "%s", but is expected to be one of "%s"', $option, $options[$option], implode('", "', $allowedValues)));
            }
        }
    }

    /**
     * Validates that the given options match the allowed types and
     * throws an exception otherwise.
     *
     * @param array $options A list of options.
     *
     * @throws InvalidOptionsException If any of the types does not match the
     *                                 allowed types of the option.
     */
    private function validateOptionTypes(array $options)
    {
        foreach ($this->allowedTypes as $option => $allowedTypes) {
            $value = $options[$option];
            $allowedTypes = (array) $allowedTypes;

            foreach ($allowedTypes as $type) {
                $isFunction = 'is_' . $type;

                if (function_exists($isFunction) && $isFunction($value)) {
                    continue 2;
                } elseif ($value instanceof $type) {
                    continue 2;
                }
            }

            $printableValue = is_object($value)
                ? get_class($value)
                : (is_array($value)
                    ? 'Array'
                    : (string) $value);

            throw new InvalidOptionsException(sprintf(
                'The option "%s" with value "%s" is expected to be of type "%s"',
                $option,
                $printableValue,
                implode('", "', $allowedTypes)
            ));
        }
    }
}
