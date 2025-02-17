<?php

namespace Laminas\ApiTools\ContentNegotiation;

use Laminas\ApiTools\ApiProblem\ApiProblem;
use Laminas\ApiTools\ApiProblem\ApiProblemResponse;
use Laminas\Http\Header\ContentType;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\MvcEvent;

use function array_key_exists;
use function basename;
use function dirname;
use function file_exists;
use function in_array;
use function is_array;
use function is_object;
use function json_decode;
use function json_last_error;
use function method_exists;
use function parse_str;
use function preg_match;
use function sprintf;
use function strlen;
use function trim;
use function unlink;

use const JSON_ERROR_CTRL_CHAR;
use const JSON_ERROR_DEPTH;
use const JSON_ERROR_NONE;
use const JSON_ERROR_STATE_MISMATCH;
use const JSON_ERROR_SYNTAX;
use const JSON_ERROR_UTF8;

class ContentTypeListener
{
    /** @var array */
    protected $jsonErrors = [
        JSON_ERROR_DEPTH          => 'Maximum stack depth exceeded',
        JSON_ERROR_STATE_MISMATCH => 'Underflow or the modes mismatch',
        JSON_ERROR_CTRL_CHAR      => 'Unexpected control character found',
        JSON_ERROR_SYNTAX         => 'Syntax error, malformed JSON',
        JSON_ERROR_UTF8           => 'Malformed UTF-8 characters, possibly incorrectly encoded',
    ];

    /**
     * Directory where upload files were written, if any
     *
     * @var string
     */
    protected $uploadTmpDir;

    /**
     * Perform content negotiation
     *
     * For HTTP methods expecting body content, attempts to match the incoming
     * content-type against the list of allowed content types, and then performs
     * appropriate content deserialization.
     *
     * If an error occurs during deserialization, an ApiProblemResponse is
     * returned, indicating an issue with the submission.
     *
     * @return null|ApiProblemResponse
     */
    public function __invoke(MvcEvent $e)
    {
        $request = $e->getRequest();
        if (! method_exists($request, 'getHeaders')) {
            // Not an HTTP request; nothing to do
            return;
        }

        $routeMatch    = $e->getRouteMatch();
        $parameterData = new ParameterDataContainer();

        // route parameters:
        $routeParams = $routeMatch->getParams();
        $parameterData->setRouteParams($routeParams);

        // query parameters:
        $parameterData->setQueryParams($request->getQuery()->toArray());

        // body parameters:
        $bodyParams = [];
        /** @psalm-var Request $request */
        $contentType = $request->getHeader('Content-Type');
        /** @var null|ContentType $contentType */
        switch ($request->getMethod()) {
            case $request::METHOD_POST:
                if ($contentType && $contentType->match('application/json')) {
                    $bodyParams = $this->decodeJson($request->getContent());
                    break;
                }

                $bodyParams = $request->getPost()->toArray();
                break;
            case $request::METHOD_PATCH:
            case $request::METHOD_PUT:
            case $request::METHOD_DELETE:
                $content = $request->getContent();

                if ($contentType && $contentType->match('multipart/form-data')) {
                    try {
                        $parser     = new MultipartContentParser($contentType, $request);
                        $bodyParams = $parser->parse();
                    } catch (Exception\ExceptionInterface $e) {
                        $bodyParams = new ApiProblemResponse(new ApiProblem(
                            400,
                            $e
                        ));
                        break;
                    }

                    if ($request->getFiles()->count()) {
                        $this->attachFileCleanupListener($e, $parser->getUploadTempDir());
                    }
                    break;
                }

                if ($contentType && $contentType->match('application/json')) {
                    $bodyParams = $this->decodeJson($content);
                    break;
                }

                // Try to assume JSON if content starts like JSON and no explicit Content-Type was provided
                if (! $bodyParams && strlen($content) > 0 && in_array($content[0], ['{', '['], true)) {
                    $bodyParams = $this->decodeJson($content);
                }

                if (! $bodyParams || $bodyParams instanceof ApiProblemResponse) {
                    parse_str($content, $bodyParams);
                }
                break;
            default:
                break;
        }

        if ($bodyParams instanceof ApiProblemResponse) {
            return $bodyParams;
        }

        $parameterData->setBodyParams($bodyParams);
        $e->setParam('LaminasContentNegotiationParameterData', $parameterData);
    }

    /**
     * Remove upload files if still present in filesystem
     *
     * @return void
     */
    public function onFinish(MvcEvent $e)
    {
        $request = $e->getRequest();

        foreach ($request->getFiles() as $fileInfo) {
            if (dirname($fileInfo['tmp_name']) !== $this->uploadTmpDir) {
                // File was moved
                continue;
            }

            if (! preg_match('/^laminasc/', basename($fileInfo['tmp_name']))) {
                // File was moved
                continue;
            }

            if (! file_exists($fileInfo['tmp_name'])) {
                continue;
            }

            unlink($fileInfo['tmp_name']);
        }
    }

    /**
     * Attempt to decode a JSON string
     *
     * Decodes a JSON string and returns it; if invalid, returns
     * an ApiProblemResponse.
     *
     * @param  string $json
     * @return mixed|ApiProblemResponse
     */
    public function decodeJson($json)
    {
        // Trim whitespace from front and end of string to avoid parse errors
        $json = trim($json);

        // If the data is empty, return an empty array to prevent JSON decode errors
        if (empty($json)) {
            return [];
        }

        $data    = json_decode($json, true);
        $isArray = is_array($data);

        // Decode 'application/hal+json' to 'application/json' by merging _embedded into the array
        if ($isArray && isset($data['_embedded'])) {
            foreach ($data['_embedded'] as $key => $value) {
                $data[$key] = $value;
            }
            unset($data['_embedded']);
        }

        if ($isArray) {
            return $data;
        }

        $error = json_last_error();
        if ($error === JSON_ERROR_NONE && $isArray) {
            return $data;
        }

        $message = array_key_exists($error, $this->jsonErrors) ? $this->jsonErrors[$error] : 'Unknown error';

        return new ApiProblemResponse(
            new ApiProblem(400, sprintf('JSON decoding error: %s', $message))
        );
    }

    /**
     * Attach the file cleanup listener
     *
     * @param string $uploadTmpDir Directory in which file uploads were made
     * @return void
     */
    protected function attachFileCleanupListener(MvcEvent $event, $uploadTmpDir)
    {
        $target = $event->getTarget();
        if (! $target || ! is_object($target) || ! method_exists($target, 'getEventManager')) {
            return;
        }

        $this->uploadTmpDir = $uploadTmpDir;
        $events             = $target->getEventManager();
        $events->attach('finish', [$this, 'onFinish'], 1000);
    }
}
