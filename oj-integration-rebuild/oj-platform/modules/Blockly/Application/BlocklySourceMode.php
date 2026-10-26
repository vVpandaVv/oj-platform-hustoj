<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageIds;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourcePayload;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;

final class BlocklySourceMode implements SubmissionSourceMode
{
    public function __construct(
        private readonly BlocklyWorkspaceValidator $validator,
        private readonly BlocklyPythonGenerator $generator,
        private readonly BlocklyStoredSourceCodec $codec
    ) {
    }

    public function key(): string
    {
        return 'blockly';
    }

    public function build(
        SubmissionSourceRequest $request
    ): SubmissionSourcePayload {
        $workspace = $this->validator->validate(
            $request->blocklyWorkspace(),
            $request->blocklyProfile()
        );
        $source = $this->generator->generate($workspace);
        $storedSource = $this->codec->encode(
            $source,
            $workspace->canonicalJson()
        );

        return new SubmissionSourcePayload(
            $this->key(),
            SubmissionLanguageIds::PYTHON_3,
            $source,
            $storedSource,
            [['start' => 0, 'length' => strlen($source)]],
            true,
            [
                'blockly_profile' => $workspace->profile(),
                'block_count' => $workspace->blockCount(),
                'format' => BlocklyProfileCatalog::FORMAT,
                'generator_version' => BlocklyProfileCatalog::GENERATOR_VERSION,
            ]
        );
    }
}
