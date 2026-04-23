<?php

namespace App\Components\PromptAssembler;

use App\Components\RequestPipeline\DTO\ResponseFormatConfig;

class StructuredOutputFallback
{
    public function injectSchemaIntoSystemPrompt(string $systemPrompt, ResponseFormatConfig $format): string
    {
        $schema = json_decode($format->schema, true) ?: [];
        $prettySchema = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $injection = "RESPONSE FORMAT REQUIREMENT:\n";
        $injection .= "You MUST respond with a valid JSON object that strictly conforms to the following JSON Schema.\n";
        $injection .= "Do NOT include any text before or after the JSON. Output ONLY the JSON object.\n";
        $injection .= "\n";
        $injection .= "Schema name: {$format->name}\n";
        $injection .= "\n";
        $injection .= "JSON Schema:\n";
        $injection .= $prettySchema;

        if ($format->strict === true) {
            $injection .= "\n\n";
            $injection .= "STRICT MODE: No additional properties are allowed beyond those defined in the schema.\n";
            $injection .= "All required fields MUST be present. Enum values MUST match exactly.";
        }

        return $systemPrompt . "\n\n" . $injection;
    }
}
