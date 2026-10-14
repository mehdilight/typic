<?php

declare(strict_types=1);

namespace Typic\Server;

use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth as BaseQueryDepth;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * Limits checked before a query runs, so one expensive query cannot tie up the server.
 */
final class QueryLimits
{
    public function __construct(
        public readonly ?int $maxDepth = null,
        public readonly ?int $maxComplexity = null,
        public readonly bool $introspection = true,
    ) {
    }

    /**
     * The validation rules for one execution, or null for graphql-php's defaults.
     *
     * @return array<ValidationRule>|null
     */
    public function rules(): ?array
    {
        if ($this->maxDepth === null && $this->maxComplexity === null && $this->introspection) {
            return null;
        }

        // New instances each time: these rules keep state while they run
        $rules = DocumentValidator::allRules();
        if ($this->maxDepth !== null) {
            $rules[BaseQueryDepth::class] = new QueryDepth($this->maxDepth);
        }
        if ($this->maxComplexity !== null) {
            $rules[QueryComplexity::class] = new QueryComplexity($this->maxComplexity);
        }
        if (!$this->introspection) {
            $rules[DisableIntrospection::class] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }

        return $rules;
    }
}
