<?php
/**
 * @package   Angeo_McpServer
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

// Magento generates *Factory classes into generated/code at compile time. A
// plain unit-test run (CI without a Magento installation) has no such
// directory, so the factories a test mocks are declared here — only when the
// real generated class is not already available.
// phpcs:disable

namespace Magento\Integration\Model\Oauth;

if (!class_exists(TokenFactory::class)) {
    class TokenFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Token
        {
            throw new \LogicException('Test double only: mock this factory.');
        }
    }
}

namespace Magento\Framework\Controller\Result;

if (!class_exists(JsonFactory::class)) {
    class JsonFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Json
        {
            throw new \LogicException('Test double only: mock this factory.');
        }
    }
}

namespace Magento\Integration\Api\Data;

if (!class_exists(UserTokenParametersInterfaceFactory::class)) {
    class UserTokenParametersInterfaceFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): UserTokenParametersInterface
        {
            throw new \LogicException('Test double only: mock this factory.');
        }
    }
}
