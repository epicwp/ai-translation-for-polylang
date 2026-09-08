<?php
declare(strict_types=1);

namespace PLLAT\Translator\Models;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object representing the user's translation intent.
 *
 * Carried on `runs.spec` (JSON-encoded). Reads at run-creation time, never
 * mutates afterwards. Single-translator runs are just specs with a
 * one-element id_list in post_query (or term_query for term translators).
 */
final class Run_Spec {

    /**
     * @param array<string, mixed>|null $post_query
     * @param array<string, mixed>|null $term_query
     * @param array<int, string>        $target_languages
     * @param array<int, string>|null   $force_fields
     * @param int|null                  $limit            Cap on candidate (post|term) count per kind. Null = no cap.
     * @param string                    $instructions     Custom per-run translation instructions.
     */
    public function __construct(
        private readonly ?array $post_query,
        private readonly ?array $term_query,
        private readonly array $target_languages,
        private readonly bool $force,
        private readonly ?array $force_fields,
        private readonly ?int $limit = null,
        private readonly string $instructions = '',
    ) {}

    public function post_query(): ?array {
        return $this->post_query;
    }

    public function term_query(): ?array {
        return $this->term_query;
    }

    /**
     * @return array<int, string>
     */
    public function target_languages(): array {
        return $this->target_languages;
    }

    public function is_force(): bool {
        return $this->force;
    }

    /**
     * @return array<int, string>|null
     */
    public function force_fields(): ?array {
        return $this->force_fields;
    }

    public function limit(): ?int {
        return $this->limit;
    }

    public function instructions(): string {
        return $this->instructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return array(
            'post_query'       => $this->post_query,
            'term_query'       => $this->term_query,
            'target_languages' => $this->target_languages,
            'force'            => $this->force,
            'force_fields'     => $this->force_fields,
            'limit'            => $this->limit,
            'instructions'     => $this->instructions,
        );
    }

    public function to_json(): string {
        return (string) \json_encode( $this->to_array() );
    }

    public static function from_json( string $json ): self {
        $data = \json_decode( $json, true );
        if ( ! \is_array( $data ) ) {
            $data = array();
        }
        $limit_raw = $data['limit'] ?? null;
        return new self(
            post_query:       \is_array( $data['post_query'] ?? null ) ? $data['post_query'] : null,
            term_query:       \is_array( $data['term_query'] ?? null ) ? $data['term_query'] : null,
            target_languages: \is_array( $data['target_languages'] ?? null ) ? \array_values( $data['target_languages'] ) : array(),
            force:            (bool) ( $data['force'] ?? false ),
            force_fields:     \is_array( $data['force_fields'] ?? null ) ? \array_values( $data['force_fields'] ) : null,
            limit:            \is_int( $limit_raw ) && $limit_raw > 0 ? $limit_raw : null,
            instructions:     \is_string( $data['instructions'] ?? null ) ? $data['instructions'] : '',
        );
    }

    /**
     * Build a single-post spec. Used by Single_Translator REST controller
     * to call create_run with a one-element id_list.
     *
     * post_type is required by Job_Claim_Service's post query (empty list
     * short-circuits, same constraint as for_single_term's taxonomy).
     * The caller resolves the actual post_type via get_post() and passes it.
     *
     * @param array<int, string>      $target_languages
     * @param array<int, string>|null $force_fields
     */
    public static function for_single_post(
        int $post_id,
        string $source_lang,
        array $target_languages,
        bool $force = false,
        ?array $force_fields = null,
        string $post_type = '',
        string $instructions = '',
    ): self {
        return new self(
            post_query: array(
                'post_types'  => '' !== $post_type ? array( $post_type ) : array(),
                'source_lang' => $source_lang,
                'id_list'     => array( $post_id ),
            ),
            term_query: null,
            target_languages: $target_languages,
            force: $force,
            force_fields: $force_fields,
            instructions: $instructions,
        );
    }

    /**
     * Build a single-term spec. Mirror of for_single_post for term translators.
     *
     * Taxonomy is required by Job_Claim_Service's term query (empty list short-circuits).
     * Pass the term's own taxonomy so the query can join correctly.
     *
     * @param array<int, string>      $target_languages
     * @param array<int, string>|null $force_fields
     */
    public static function for_single_term(
        int $term_id,
        string $source_lang,
        array $target_languages,
        bool $force = false,
        ?array $force_fields = null,
        string $taxonomy = '',
        string $instructions = '',
    ): self {
        return new self(
            post_query: null,
            term_query: array(
                'taxonomies'  => '' !== $taxonomy ? array( $taxonomy ) : array(),
                'source_lang' => $source_lang,
                'id_list'     => array( $term_id ),
            ),
            target_languages: $target_languages,
            force: $force,
            force_fields: $force_fields,
            instructions: $instructions,
        );
    }
}
