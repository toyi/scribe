<?php

namespace Knuckles\Scribe\Extracting\Strategies\BodyParameters;

use Dingo\Api\Http\FormRequest as DingoFormRequest;
use Illuminate\Foundation\Http\FormRequest as LaravelFormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Knuckles\Scribe\Extracting\BodyParameterDefinition;
use Knuckles\Scribe\Extracting\ParamHelpers;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Knuckles\Scribe\Extracting\ValidationRuleDescriptionParser as Description;
use Knuckles\Scribe\Tools\Utils;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class GetFromFormRequest extends Strategy
{
    public $stage = 'bodyParameters';

    public static $MISSING_VALUE;

    use ParamHelpers;

    public function __invoke(Route $route, ReflectionClass $controller, ReflectionFunctionAbstract $method, array $routeRules, array $context = []): array
    {
        return $this->getBodyParametersFromFormRequest($method);
    }

    public function getBodyParametersFromFormRequest(ReflectionFunctionAbstract $method): array
    {
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType === null) {
                continue;
            }

            $parameterClassName = $paramType->getName();

            try {
                $parameterClass = new ReflectionClass($parameterClassName);
            } catch (ReflectionException $e) {
                continue;
            }

            // If there's a FormRequest, we check there for @bodyParam tags.
            if (
                (class_exists(LaravelFormRequest::class) && $parameterClass->isSubclassOf(LaravelFormRequest::class))
                || (class_exists(DingoFormRequest::class) && $parameterClass->isSubclassOf(DingoFormRequest::class))) {
                $bodyParametersFromDocBlock = $this->getBodyParametersFromValidationRules($this->getRouteValidationRules($parameterClassName));

                return $bodyParametersFromDocBlock;
            }
        }

        return [];
    }

    protected function getRouteValidationRules(string $formRequestClass)
    {
        /** @var LaravelFormRequest|DingoFormRequest $formRequest */
        $formRequest = new $formRequestClass;/*
        // Add route parameter bindings
        $formRequest->setContainer(app());
        $formRequest->request->add($bindings);
        $formRequest->query->add($bindings);
        $formRequest->setMethod($routeMethods[0]);*/

        if (method_exists($formRequest, 'validator')) {
            $validationFactory = app(ValidationFactory::class);

            return call_user_func_array([$formRequest, 'validator'], [$validationFactory])
                ->getRules();
        } else {
            return call_user_func_array([$formRequest, 'rules'], []);
        }
    }

    public function getBodyParametersFromValidationRules(array $validationRules)
    {
        self::$MISSING_VALUE = new \stdClass();
        $rules = $this->normaliseRules($validationRules);

        $parameters = [];
        foreach ($rules as $parameter => $ruleset) {
            $parameterData = [
                'required' => false,
                'type' => null,
                'value' => self::$MISSING_VALUE,
                'scribe_value' => self::$MISSING_VALUE,
                'description' => '',
                'visible_to_scribe' => false,
            ];
            foreach ($ruleset as $rule) {
                $this->parseRule($rule, $parameterData);
            }

            // Ignore parameters that didn't use our custom rule
            if (!$parameterData['visible_to_scribe']) {
                continue;
            }
            unset($parameterData['visible_to_scribe']);

            // Make sure the user-specified description comes first.
            $businessDescription = $parameterData['scribe_description'] ?? '';
            $validationDescription = trim($parameterData['description'] ?: '');
            $fullDescription = trim($businessDescription . ' ' .trim($validationDescription));
            // Let's have our sentences end with full stops, like civilized people.🙂
            $parameterData['description'] = $fullDescription ? rtrim($fullDescription, '.').'.' : $fullDescription;
            unset($parameterData['scribe_description']);

            // Set default values for type
            if (is_null($parameterData['type'])) {
                $parameterData['type'] = 'string';
            }
            // Set values when parameter is required and has no value
            if ($parameterData['required'] === true && $parameterData['value'] === self::$MISSING_VALUE) {
                $parameterData['value'] = $this->generateDummyValue($parameterData['type']);
            }

            // Make sure the user-specified example overwrites others.
            if ($parameterData['scribe_value'] !== self::$MISSING_VALUE) {
                $parameterData['value'] = $parameterData['scribe_value'];
            }
            unset($parameterData['scribe_value']);

            if (!is_null($parameterData['value']) && $parameterData['value'] !== self::$MISSING_VALUE) {
                // Cast is important since values had been cast to string when serializing the validator
                $parameterData['value'] = $this->castToType($parameterData['value'], $parameterData['type']);
            }

            $parameters[$parameter] = $parameterData;
        }

        return $parameters;
    }

    /**
     * This method will transform validation rules from:
     * 'param1' => 'int|required'  TO  'param1' => ['int', 'required']
     *
     * @param array<string,string|string[]> $rules
     *
     * @return mixed
     */
    protected function normaliseRules(array $rules)
    {
        // We can simply call Validator::make($data, $rules)->getRules() to get the normalised rules,
        // but Laravel will ignore any nested array rules (`ids.*')
        // unless the key referenced (`ids`) exists in the dataset and is a non-empty array
        // So we'll create a single-item array for each array parameter
        $values = collect($rules)
            ->filter(function ($value, $key) {
                return Str::contains($key, '.*');
            })->mapWithKeys(function ($value, $key) {
                if (Str::endsWith($key, '.*')) {
                    // We're dealing with a simple array of primitives
                    return [Str::substr($key, 0, -2) => [Str::random()]];
                } elseif (Str::contains($key, '.*.')) {
                    // We're dealing with an array of objects
                    [$key, $property] = explode('.*.', $key);

                    // Even though this will be overwritten by another property declaration in the rules, we're fine.
                    // All we need is for Laravel to see this key exists
                    return [$key => [[$property => Str::random()]]];
                }
            })->all();

        // Now this will return the complete ruleset.
        // Nested array parameters will be present, with '*' replaced by '0'
        $newRules =  Validator::make($values, $rules)->getRules();

        // Transform the key names back from 'ids.0' to 'ids.*'
        return collect($newRules)->mapWithKeys(function ($val, $paramName) use ($rules) {
            if (Str::contains($paramName, '.0')) {
                $genericArrayKeyName = str_replace('.0', '.*', $paramName);

                // But only if that was the original value
                if (isset($rules[$genericArrayKeyName])) {
                    $paramName = $genericArrayKeyName;
                }
            }

            return [$paramName => $val];
        })->toArray();
    }

    protected function parseRule($rule, &$parameterData)
    {
        $parsedRule = $this->parseStringRuleIntoRuleAndArguments($rule);
        [$rule, $arguments] = $parsedRule;

        // Reminders:
        // 1. Append to the description (with a leading space); don't overwrite.
        // 2. Avoid testing on the value of $parameterData['type'],
        // as that may not have been set yet, since the rules can be in any order.
        // For this reason, only deterministic rules are supported
        // 3. All rules supported must be rules that we can generate a valid dummy value for.
        switch ($rule) {
            case BodyParameterDefinition::RULENAME:
                $parameterData['scribe_description'] = $arguments[0];
                $parameterData['scribe_value'] = $arguments[1] == ''  ? self::$MISSING_VALUE : $arguments[1];
                $parameterData['visible_to_scribe'] = true;
                break;

            case 'required':
                $parameterData['required'] = true;
                break;

            /*
             * Primitive types. No description should be added
            */
            case 'bool':
            case 'boolean':
                $parameterData['value'] = Arr::random([true, false]);
                $parameterData['type'] = 'boolean';
                break;
            case 'string':
                $parameterData['value'] = $this->generateDummyValue('string');
                $parameterData['type'] = 'string';
                break;
            case 'int':
            case 'integer':
                $parameterData['value'] = $this->generateDummyValue('integer');
                $parameterData['type'] = 'integer';
                break;
            case 'numeric':
                $parameterData['value'] = $this->generateDummyValue('number');
                $parameterData['type'] = 'number';
                break;
            case 'array':
                $parameterData['value'] = [$this->generateDummyValue('string')];
                $parameterData['type'] = $rule;
                break;
            case 'file':
                $parameterData['type'] = 'file';
                break;

            /**
             * Special string types
             */
            case 'timezone':
                // Laravel's message merely says "The value must be a valid zone"
                $parameterData['description'] .= "The value must be a valid time zone, such as `Africa/Accra`. ";
                $parameterData['value'] = $this->getFaker()->timezone;
                break;
            case 'email':
                $parameterData['description'] .= Description::getDescription($rule).' ';
                $parameterData['value'] = $this->getFaker()->safeEmail;
                $parameterData['type'] = 'string';
                break;
            case 'url':
                $parameterData['value'] = $this->getFaker()->url;
                $parameterData['type'] = 'string';
                // Laravel's message is "The value format is invalid". Ugh.🤮
                $parameterData['description'] .= "The value must be a valid URL. ";
                break;
            case 'ip':
                $parameterData['description'] .= Description::getDescription($rule).' ';
                $parameterData['value'] = $this->getFaker()->ipv4;
                $parameterData['type'] = 'string';
                break;
            case 'json':
                $parameterData['type'] = 'string';
                $parameterData['description'] .= Description::getDescription($rule).' ';
                $parameterData['value'] = json_encode([$this->getFaker()->word, $this->getFaker()->word,]);
                break;
            case 'date':
                $parameterData['type'] = 'string';
                $parameterData['description'] .= Description::getDescription($rule).' ';
                $parameterData['value'] = date(\DateTime::ISO8601, time());
                break;
            case 'date_format':
                $parameterData['type'] = 'string';
                // Laravel description here is "The value must match the format Y-m-d". Not descriptive enough.
                $parameterData['description'] .= "The value must be a valid date in the format {$arguments[0]} ";
                $parameterData['value'] = date($arguments[0], time());
                break;

            /**
             * Special number types. Some rules here may apply to other types, but we treat them as being numeric.
             *//*
         * min, max and between not supported until we can figure out a proper way
         *  to make them compatible with multiple types (string, number, file)
            case 'min':
                $parameterData['type'] = $parameterData['type'] ?: 'number';
                $parameterData['description'] .= Description::getDescription($rule, [':min' => $arguments[0]], 'numeric').' ';
                $parameterData['value'] = $this->getFaker()->numberBetween($arguments[0]);
                break;
            case 'max':
                $parameterData['type'] = $parameterData['type'] ?: 'number';
                $parameterData['description'] .= Description::getDescription($rule, [':max' => $arguments[0]], 'numeric').' ';
                $parameterData['value'] = $this->getFaker()->numberBetween(0, $arguments[0]);
                break;
            case 'between':
                $parameterData['type'] = $parameterData['type'] ?: 'number';
                $parameterData['description'] .= Description::getDescription($rule, [':min' => $arguments[0], ':max' => $arguments[1]], 'numeric').' ';
                $parameterData['value'] = $this->getFaker()->numberBetween($arguments[0], $arguments[1]);
                break;*/

            /**
             * Special file types.
             */
            case 'image':
                $parameterData['type'] = 'file';
                $parameterData['description'] .= Description::getDescription($rule).' ';
                break;

            /**
             * Other rules.
             */
            case 'in':
                // Not using the rule description here because it only says "The attribute is invalid"
                $description = 'The value must be one of '.Utils::getArrayAsFriendlyMarkdownString($arguments);
                $parameterData['description'] .= $description.' ';
                $parameterData['value'] = Arr::random($arguments);
                break;

            default:
                // Other rules not supported
                break;
        }
    }

    /**
     * Parse a string rule into the base rule and arguments.
     * Laravel validation rules are specified in the format {rule}:{arguments}
     * Arguments are separated by commas.
     * For instance the rule "max:3" states that the value may only be three letters.
     *
     * @param  string  $rule
     *
     * @return array
     */
    protected function parseStringRuleIntoRuleAndArguments($rule)
    {
        $ruleArguments = [];
        
        // Convert any Rule objects to strings
        if ($rule instanceof \Illuminate\Contracts\Validation\Rule) {
            $className = substr(strrchr(get_class($rule), "\\"), 1);
            return [$className, []];
        }
        
        if (strpos($rule, ':') !== false) {
            [$rule, $argumentsString] = explode(':', $rule, 2);

            // These rules can have ommas in their arguments, so we don't split on commas
            if (in_array(strtolower($rule), ['regex', 'date', 'date_format'])) {
                $ruleArguments = [$argumentsString];
                // For our custom rule, we're using a different delimiter, since descriptions may contain commas.
            } elseif (strtolower($rule) === BodyParameterDefinition::RULENAME) {
                $ruleArguments = explode(BodyParameterDefinition::DELIMITER, $argumentsString);
            } else {
                $ruleArguments = str_getcsv($argumentsString);
            }
        }

        return [strtolower(trim($rule)), $ruleArguments];
    }
}
