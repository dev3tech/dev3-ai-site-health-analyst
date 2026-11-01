<?php
/**
 * Minimal Google Gemini REST client built on the WordPress HTTP API.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Api;

use Dev3\AiSiteHealth\Core3\Core\Settings_Store as Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps models.generateContent and models.list.
 */
class Gemini_Client {

	const BASE = 'https://generativelanguage.googleapis.com/v1beta/';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Key; defaults to the stored one.
	 */
	public function __construct( $api_key = null ) {
		$this->api_key = null === $api_key ? Settings::api_key() : (string) $api_key;
	}

	/**
	 * Runs a generation.
	 *
	 * @param array $args {
	 *     Generation arguments.
	 *     @type string $model       Model id.
	 *     @type string $system      System instruction.
	 *     @type string $prompt      User prompt.
	 *     @type array  $images      List of [ 'mime' => string, 'data' => base64 string ].
	 *     @type float  $temperature 0..1.
	 *     @type int    $max_tokens  Max output tokens.
	 *     @type array  $schema      Optional responseSchema (forces JSON output).
	 *     @type bool   $json        Force application/json without a schema.
	 *     @type int    $candidates  Number of candidates (variations).
	 *     @type int    $timeout     Seconds (default 90).
	 *     @type int    $retries     Extra attempts on 429 rate limits / 5xx / network errors (default 0, max 3).
	 *     @type array  $tools       Function declarations ({ name, description, parameters }) for function calling.
	 *     @type string $tool_mode   AUTO|ANY|NONE.
	 *     @type array  $contents    Raw `contents` turns (multi-turn chats, function responses); replaces prompt/images.
	 * }
	 * @return array|\WP_Error { text, texts, function_calls [ {name, args} ], model, finish, usage{prompt,output,total}, duration_ms }
	 */
	public function generate( array $args ) {
		if ( '' === $this->api_key ) {
			return Api_Error::missing_key();
		}

		$model = Settings::sanitize_model( isset( $args['model'] ) ? $args['model'] : Settings::get( 'default_model' ) );
		if ( '' === $model ) {
			$model = 'gemini-2.5-flash';
		}

		$parts = array();
		if ( ! empty( $args['images'] ) ) {
			foreach ( $args['images'] as $image ) {
				$parts[] = array(
					'inline_data' => array(
						'mime_type' => $image['mime'],
						'data'      => $image['data'],
					),
				);
			}
		}
		$parts[] = array( 'text' => isset( $args['prompt'] ) ? (string) $args['prompt'] : '' );

		$config = array(
			'temperature'     => isset( $args['temperature'] ) ? (float) $args['temperature'] : (float) Settings::get( 'temperature', 0.7 ),
			'maxOutputTokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : (int) Settings::get( 'max_tokens', 4096 ),
		);
		if ( ! empty( $args['candidates'] ) && (int) $args['candidates'] > 1 ) {
			$config['candidateCount'] = min( 4, (int) $args['candidates'] );
		}
		if ( ! empty( $args['schema'] ) ) {
			$config['responseMimeType'] = 'application/json';
			$config['responseSchema']   = $args['schema'];
		} elseif ( ! empty( $args['json'] ) ) {
			$config['responseMimeType'] = 'application/json';
		}
		$thinking = isset( $args['thinking_budget'] )
			? self::budget_config( $model, (int) $args['thinking_budget'] )
			: self::thinking_config( $model, (string) Settings::get( 'reasoning', 'low' ), ! empty( $args['light_thinking'] ) );
		if ( $thinking ) {
			$config['thinkingConfig'] = $thinking;
		}

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig' => $config,
		);
		if ( ! empty( $args['system'] ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $args['system'] ) ) );
		}

		if ( ! empty( $args['contents'] ) && is_array( $args['contents'] ) ) {
			// Multi-turn conversations and function responses supply their own turns.
			$body['contents'] = $args['contents'];
		}
		if ( ! empty( $args['tools'] ) && is_array( $args['tools'] ) ) {
			$body['tools'] = array( array( 'functionDeclarations' => array_values( $args['tools'] ) ) );
			if ( ! empty( $args['tool_mode'] ) ) {
				$body['toolConfig'] = array( 'functionCallingConfig' => array( 'mode' => strtoupper( (string) $args['tool_mode'] ) ) );
			}
		}

		/**
		 * Filters the generateContent request body before it is sent.
		 *
		 * @param array $body  Request body.
		 * @param array $args  Original arguments.
		 * @param string $model Model id.
		 */
		$body = apply_filters( 'dev3sh_request_body', $body, $args, $model );

		$response = $this->post( 'models/' . rawurlencode( $model ) . ':generateContent', $body, isset( $args['timeout'] ) ? (int) $args['timeout'] : 90, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return self::parse( $response['body'], $model, $response['duration_ms'] );
	}

	/**
	 * POSTs JSON to the Gemini API with optional retries (exponential backoff) on rate limits,
	 * upstream 5xx and network failures. Timeouts are never retried: they would double the wait.
	 *
	 * @param string $path    Path under BASE.
	 * @param array  $body    Request body.
	 * @param int    $timeout Seconds.
	 * @param array  $args    Original args (`retries` = extra attempts, default 0).
	 * @return array|\WP_Error { body (decoded), duration_ms }
	 */
	private function post( $path, array $body, $timeout, array $args = array() ) {
		$retries = isset( $args['retries'] ) ? max( 0, min( 3, (int) $args['retries'] ) ) : 0;
		$started = microtime( true );
		$attempt = 0;
		while ( true ) {
			$response = wp_remote_post(
				self::BASE . $path,
				array(
					'timeout' => (int) apply_filters( 'dev3sh_request_timeout', $timeout, $args ),
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $this->api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			$error    = null;
			$retry    = false;
			if ( is_wp_error( $response ) ) {
				$error = Api_Error::from_transport( $response );
				$retry = 'dev3sh_timeout' !== $error->get_error_code();
			} else {
				$http    = (int) wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				$decoded = is_array( $decoded ) ? $decoded : array();
				if ( $http >= 200 && $http < 300 ) {
					return array(
						'body'        => $decoded,
						'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
					);
				}
				$error = Api_Error::from_response( $http, $decoded );
				$raw   = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';
				$retry = $http >= 500 || ( 429 === $http && false === stripos( $raw, 'per day' ) );
			}
			if ( ! $retry || $attempt >= $retries ) {
				return $error;
			}
			++$attempt;
			/**
			 * Milliseconds to wait before retry N (1-based). Tests set it to 0.
			 *
			 * @param int $delay   Delay in ms.
			 * @param int $attempt Retry number.
			 */
			$delay = (int) apply_filters( 'dev3sh_retry_delay_ms', 1000 * ( 2 ** ( $attempt - 1 ) ), $attempt );
			if ( $delay > 0 ) {
				usleep( $delay * 1000 );
			}
		}
	}

	/**
	 * Embeds texts with batchEmbedContents (up to 100 texts per call, batched automatically).
	 *
	 * Vectors are L2-normalised, so cosine similarity is a plain dot product.
	 *
	 * @param string|string[] $texts     Text or list of texts.
	 * @param string          $task_type RETRIEVAL_DOCUMENT|RETRIEVAL_QUERY|SEMANTIC_SIMILARITY|CLASSIFICATION|CLUSTERING|QUESTION_ANSWERING|FACT_VERIFICATION|CODE_RETRIEVAL_QUERY.
	 * @param array           $opts      { model, dimensions (default 768), batch (<=100), timeout, retries }.
	 * @return array|\WP_Error { vectors (list of float lists, same order as $texts), model, dimensions, usage{prompt,output,total,estimated}, duration_ms }
	 */
	public function embed( $texts, $task_type = 'RETRIEVAL_DOCUMENT', array $opts = array() ) {
		if ( '' === $this->api_key ) {
			return Api_Error::missing_key();
		}
		$texts = array_values( array_map( 'strval', (array) $texts ) );
		$model = Settings::sanitize_model( isset( $opts['model'] ) ? $opts['model'] : Settings::get( 'embedding_model', 'gemini-embedding-001' ) );
		$model = '' === $model ? 'gemini-embedding-001' : $model;
		$dims  = isset( $opts['dimensions'] ) ? (int) $opts['dimensions'] : 768;
		$dims  = max( 128, min( 3072, $dims ) );
		$batch = isset( $opts['batch'] ) ? max( 1, min( 100, (int) $opts['batch'] ) ) : 100;
		$type  = strtoupper( preg_replace( '/[^A-Za-z_]/', '', (string) $task_type ) );
		$type  = '' === $type ? 'RETRIEVAL_DOCUMENT' : $type;

		$vectors  = array();
		$duration = 0;
		$tokens   = 0;
		foreach ( array_chunk( $texts, $batch ) as $chunk ) {
			$requests = array();
			foreach ( $chunk as $text ) {
				$tokens    += (int) ceil( strlen( $text ) / 4 );
				$requests[] = array(
					'model'                => 'models/' . $model,
					'content'              => array( 'parts' => array( array( 'text' => $text ) ) ),
					'taskType'             => $type,
					'outputDimensionality' => $dims,
				);
			}
			$response = $this->post( 'models/' . rawurlencode( $model ) . ':batchEmbedContents', array( 'requests' => $requests ), isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 60, $opts );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$duration += $response['duration_ms'];
			$items     = isset( $response['body']['embeddings'] ) && is_array( $response['body']['embeddings'] ) ? $response['body']['embeddings'] : array();
			if ( count( $items ) !== count( $chunk ) ) {
				return Api_Error::make( 'dev3sh_bad_embedding', __( 'The AI returned an incomplete embedding batch.', 'dev3-ai-site-health-analyst' ), __( 'Try again in a moment.', 'dev3-ai-site-health-analyst' ), 502, true );
			}
			foreach ( $items as $item ) {
				$vectors[] = self::normalize( isset( $item['values'] ) ? (array) $item['values'] : array() );
			}
		}
		return array(
			'vectors'     => $vectors,
			'model'       => $model,
			'dimensions'  => $vectors ? count( $vectors[0] ) : $dims,
			'usage'       => array(
				'prompt'    => $tokens,
				'output'    => 0,
				'total'     => $tokens,
				'estimated' => true,
			),
			'duration_ms' => $duration,
		);
	}

	/**
	 * L2-normalises a vector.
	 *
	 * @param array $vector Floats.
	 * @return float[]
	 */
	public static function normalize( array $vector ) {
		$sum = 0.0;
		foreach ( $vector as $i => $v ) {
			$vector[ $i ] = (float) $v;
			$sum         += $vector[ $i ] * $vector[ $i ];
		}
		if ( $sum <= 0 ) {
			return array_values( $vector );
		}
		$norm = sqrt( $sum );
		return array_values( array_map( static fn( $v ) => $v / $norm, $vector ) );
	}

	/**
	 * Generates an image with a Gemini image model (default gemini-2.5-flash-image).
	 *
	 * @param string $prompt What to draw. Pass `images` to edit or combine existing images.
	 * @param array  $opts   { model, images [ {mime, data} ], aspect_ratio ("1:1", "16:9", "4:3", "3:4", "9:16"…), system, timeout, retries }.
	 * @return array|\WP_Error { data (base64), mime, images [ {mime, data} ], text, model, usage, duration_ms }
	 */
	public function generate_image( $prompt, array $opts = array() ) {
		if ( '' === $this->api_key ) {
			return Api_Error::missing_key();
		}
		$model = Settings::sanitize_model( isset( $opts['model'] ) ? $opts['model'] : Settings::get( 'image_model', 'gemini-2.5-flash-image' ) );
		$model = '' === $model ? 'gemini-2.5-flash-image' : $model;
		$parts = array();
		foreach ( isset( $opts['images'] ) ? (array) $opts['images'] : array() as $image ) {
			$parts[] = array(
				'inline_data' => array(
					'mime_type' => $image['mime'],
					'data'      => $image['data'],
				),
			);
		}
		$parts[] = array( 'text' => (string) $prompt );
		$config  = array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) );
		if ( ! empty( $opts['aspect_ratio'] ) && preg_match( '/^\d{1,2}:\d{1,2}$/', (string) $opts['aspect_ratio'] ) ) {
			$config['imageConfig'] = array( 'aspectRatio' => (string) $opts['aspect_ratio'] );
		}
		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig' => $config,
		);
		if ( ! empty( $opts['system'] ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $opts['system'] ) ) );
		}
		/** This filter is documented in generate(). */
		$body     = apply_filters( 'dev3sh_request_body', $body, $opts, $model );
		$response = $this->post( 'models/' . rawurlencode( $model ) . ':generateContent', $body, isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 120, $opts );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return self::parse_image( $response['body'], $model, $response['duration_ms'] );
	}

	/**
	 * Parses an image generation response.
	 *
	 * @param array  $decoded  Response body.
	 * @param string $model    Model id.
	 * @param int    $duration Duration in ms.
	 * @return array|\WP_Error
	 */
	public static function parse_image( array $decoded, $model, $duration = 0 ) {
		$images = array();
		$text   = '';
		$finish = '';
		foreach ( isset( $decoded['candidates'] ) ? (array) $decoded['candidates'] : array() as $cand ) {
			$finish = isset( $cand['finishReason'] ) ? (string) $cand['finishReason'] : $finish;
			foreach ( isset( $cand['content']['parts'] ) ? (array) $cand['content']['parts'] : array() as $part ) {
				$inline = isset( $part['inlineData'] ) ? $part['inlineData'] : ( isset( $part['inline_data'] ) ? $part['inline_data'] : null );
				if ( is_array( $inline ) && ! empty( $inline['data'] ) ) {
					$mime     = isset( $inline['mimeType'] ) ? $inline['mimeType'] : ( isset( $inline['mime_type'] ) ? $inline['mime_type'] : 'image/png' );
					$images[] = array(
						'mime' => (string) $mime,
						'data' => (string) $inline['data'],
					);
				} elseif ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		if ( ! $images ) {
			$blocked = ! empty( $decoded['promptFeedback']['blockReason'] ) || in_array( $finish, array( 'SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY', 'RECITATION' ), true );
			return Api_Error::make(
				$blocked ? 'dev3sh_blocked' : 'dev3sh_no_image',
				$blocked ? __( 'The AI blocked this image request for safety reasons.', 'dev3-ai-site-health-analyst' ) : __( 'The AI did not return an image.', 'dev3-ai-site-health-analyst' ),
				$blocked ? __( 'Rephrase the prompt and try again.', 'dev3-ai-site-health-analyst' ) : __( 'Try again, or describe the image more concretely.', 'dev3-ai-site-health-analyst' ),
				422,
				! $blocked
			);
		}
		$usage = isset( $decoded['usageMetadata'] ) ? $decoded['usageMetadata'] : array();
		return array(
			'data'        => $images[0]['data'],
			'mime'        => $images[0]['mime'],
			'images'      => $images,
			'text'        => $text,
			'model'       => isset( $decoded['modelVersion'] ) ? (string) $decoded['modelVersion'] : $model,
			'usage'       => array(
				'prompt' => isset( $usage['promptTokenCount'] ) ? (int) $usage['promptTokenCount'] : 0,
				'output' => isset( $usage['candidatesTokenCount'] ) ? (int) $usage['candidatesTokenCount'] : 0,
				'total'  => isset( $usage['totalTokenCount'] ) ? (int) $usage['totalTokenCount'] : 0,
			),
			'duration_ms' => (int) $duration,
		);
	}

	/**
	 * Structured generation with the hard-won defaults: low temperature, small thinking budget on flash
	 * (thinking 0 + responseSchema can loop until MAX_TOKENS), and one near-deterministic retry when the
	 * JSON is malformed or truncated.
	 *
	 * @param array $args generate() args; `schema` required. Add maxLength on STRING properties to cap runaways.
	 * @return array|\WP_Error { data (decoded), raw (generate() result) }
	 */
	public function generate_json( array $args ) {
		$args['temperature'] = min( isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.3, 0.3 );
		if ( ! isset( $args['thinking_budget'] ) ) {
			$args['light_thinking'] = true;
		}
		$result = $this->generate( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = self::decode_json( $result['text'] );
		if ( null === $data ) {
			$args['temperature'] = 0.1;
			$retry               = $this->generate( $args );
			if ( is_wp_error( $retry ) ) {
				return $retry;
			}
			$retry['usage']['total'] += $result['usage']['total'];
			$result                   = $retry;
			$data                     = self::decode_json( $result['text'] );
		}
		if ( null === $data ) {
			return Api_Error::make(
				'dev3sh_bad_json',
				__( 'The AI returned malformed data.', 'dev3-ai-site-health-analyst' ),
				__( 'Try again in a moment.', 'dev3-ai-site-health-analyst' ),
				502,
				true
			);
		}
		return array(
			'data' => $data,
			'raw'  => $result,
		);
	}

	/**
	 * Decodes JSON tolerating code fences or leading prose.
	 *
	 * @param string $text Raw text.
	 * @return array|null
	 */
	public static function decode_json( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/^```[a-zA-Z0-9_-]*\s*\n(.*?)\n?```\s*$/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		$start = strcspn( $text, '{[' );
		$end   = max( (int) strrpos( $text, '}' ), (int) strrpos( $text, ']' ) );
		if ( $start < strlen( $text ) && $end > $start ) {
			$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Parses a successful generateContent response.
	 *
	 * @param array  $decoded  Response body.
	 * @param string $model    Model id.
	 * @param int    $duration Duration in ms.
	 * @return array|\WP_Error
	 */
	public static function parse( array $decoded, $model, $duration = 0 ) {
		if ( ! empty( $decoded['promptFeedback']['blockReason'] ) ) {
			return Api_Error::make(
				'dev3sh_blocked',
				__( 'The AI blocked this request for safety reasons.', 'dev3-ai-site-health-analyst' ),
				__( 'Rephrase the prompt and try again.', 'dev3-ai-site-health-analyst' ),
				422
			);
		}

		$texts  = array();
		$calls  = array();
		$finish = '';
		$cands  = isset( $decoded['candidates'] ) && is_array( $decoded['candidates'] ) ? $decoded['candidates'] : array();
		foreach ( $cands as $cand ) {
			$finish = isset( $cand['finishReason'] ) ? (string) $cand['finishReason'] : $finish;
			$text   = '';
			if ( ! empty( $cand['content']['parts'] ) ) {
				foreach ( $cand['content']['parts'] as $part ) {
					// Skip thought summaries.
					if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
						$text .= $part['text'];
					}
					if ( ! empty( $part['functionCall']['name'] ) ) {
						$calls[] = array(
							'name' => (string) $part['functionCall']['name'],
							'args' => isset( $part['functionCall']['args'] ) ? (array) $part['functionCall']['args'] : array(),
						);
					}
				}
			}
			if ( '' !== trim( $text ) ) {
				$texts[] = $text;
			}
		}

		if ( empty( $texts ) && empty( $calls ) ) {
			if ( 'SAFETY' === $finish || 'PROHIBITED_CONTENT' === $finish || 'RECITATION' === $finish ) {
				return Api_Error::make(
					'dev3sh_blocked',
					__( 'The AI stopped because the response was flagged.', 'dev3-ai-site-health-analyst' ),
					__( 'Rephrase the prompt and try again.', 'dev3-ai-site-health-analyst' ),
					422
				);
			}
			if ( 'MAX_TOKENS' === $finish ) {
				return Api_Error::make(
					'dev3sh_max_tokens',
					__( 'The AI ran out of output tokens before writing an answer.', 'dev3-ai-site-health-analyst' ),
					__( 'Increase Max Output Tokens in API Config, or set Reasoning to "Low".', 'dev3-ai-site-health-analyst' ),
					422,
					true
				);
			}
			return Api_Error::make(
				'dev3sh_empty',
				__( 'The AI returned an empty response.', 'dev3-ai-site-health-analyst' ),
				__( 'Try again or rephrase the prompt.', 'dev3-ai-site-health-analyst' ),
				502,
				true
			);
		}

		$usage = isset( $decoded['usageMetadata'] ) ? $decoded['usageMetadata'] : array();
		return array(
			'text'           => $texts ? $texts[0] : '',
			'texts'          => $texts,
			'function_calls' => $calls,
			'model'          => isset( $decoded['modelVersion'] ) ? (string) $decoded['modelVersion'] : $model,
			'finish'         => $finish,
			'truncated'      => 'MAX_TOKENS' === $finish,
			'usage'          => array(
				'prompt' => isset( $usage['promptTokenCount'] ) ? (int) $usage['promptTokenCount'] : 0,
				'output' => ( isset( $usage['candidatesTokenCount'] ) ? (int) $usage['candidatesTokenCount'] : 0 ) + ( isset( $usage['thoughtsTokenCount'] ) ? (int) $usage['thoughtsTokenCount'] : 0 ),
				'total'  => isset( $usage['totalTokenCount'] ) ? (int) $usage['totalTokenCount'] : 0,
			),
			'duration_ms'    => (int) $duration,
		);
	}

	/**
	 * Lists models usable for generateContent.
	 *
	 * @return array|\WP_Error List of { id, name, input_limit, output_limit }.
	 */
	public function list_models() {
		if ( '' === $this->api_key ) {
			return Api_Error::missing_key();
		}
		$models = array();
		$token  = '';
		$pages  = 0;
		do {
			$url      = add_query_arg(
				array_filter(
					array(
						'pageSize'  => 200,
						'pageToken' => $token,
					)
				),
				self::BASE . 'models'
			);
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => array( 'x-goog-api-key' => $this->api_key ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return Api_Error::from_transport( $response );
			}
			$http    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$decoded = is_array( $decoded ) ? $decoded : array();
			if ( 200 !== $http ) {
				return Api_Error::from_response( $http, $decoded );
			}
			foreach ( isset( $decoded['models'] ) ? $decoded['models'] : array() as $m ) {
				$methods = isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array();
				$id      = preg_replace( '#^models/#', '', (string) $m['name'] );
				// Text-capable Gemini models only; skip embedding, image, TTS and live-audio variants.
				if ( ! in_array( 'generateContent', $methods, true ) || 0 !== strpos( $id, 'gemini' ) || preg_match( '/(image|tts|audio|live|embedding)/', $id ) ) {
					continue;
				}
				$models[] = array(
					'id'           => $id,
					'name'         => isset( $m['displayName'] ) ? (string) $m['displayName'] : $id,
					'input_limit'  => isset( $m['inputTokenLimit'] ) ? (int) $m['inputTokenLimit'] : 0,
					'output_limit' => isset( $m['outputTokenLimit'] ) ? (int) $m['outputTokenLimit'] : 0,
				);
			}
			$token = isset( $decoded['nextPageToken'] ) ? (string) $decoded['nextPageToken'] : '';
			++$pages;
		} while ( '' !== $token && $pages < 5 );

		usort(
			$models,
			static function ( $a, $b ) {
				return strcmp( $b['id'], $a['id'] );
			}
		);
		return $models;
	}

	/**
	 * Explicit thinking budget (layouts need real planning to fill a large schema reliably).
	 *
	 * @param string $model  Model id.
	 * @param int    $budget Tokens.
	 * @return array|null
	 */
	public static function budget_config( $model, $budget ) {
		if ( preg_match( '/^gemini-2\.5-/', $model ) ) {
			return array( 'thinkingBudget' => max( 128, $budget ) );
		}
		if ( preg_match( '/^gemini-[3-9]/', $model ) ) {
			return array( 'thinkingLevel' => $budget >= 2048 ? 'high' : 'low' );
		}
		return null;
	}

	/**
	 * Thinking settings keep editor generations snappy when reasoning is "low".
	 *
	 * @param string $model     Model id.
	 * @param string $reasoning low|auto.
	 * @param bool   $light     Keep a small budget instead of none (structured vision output loops without it).
	 * @return array|null
	 */
	public static function thinking_config( $model, $reasoning, $light = false ) {
		if ( 'low' !== $reasoning ) {
			return null;
		}
		if ( preg_match( '/^gemini-2\.5-flash/', $model ) ) {
			return array( 'thinkingBudget' => $light ? 512 : 0 );
		}
		if ( preg_match( '/^gemini-2\.5-pro/', $model ) ) {
			// Pro cannot disable thinking; 128 is its minimum budget.
			return array( 'thinkingBudget' => 128 );
		}
		if ( preg_match( '/^gemini-[3-9]/', $model ) ) {
			return array( 'thinkingLevel' => 'low' );
		}
		return null;
	}
}
