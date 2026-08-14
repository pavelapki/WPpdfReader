<?php
/**
 * Reading the categories a page selects, from an ACF field.
 *
 * A page carries a field naming the document categories that belong to it,
 * and the template lists those documents. The field is configured once in the
 * settings; this turns whatever ACF hands back into term IDs.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * ACF field integration.
 */
class WPPDF_Acf {

	/**
	 * Whether ACF is available.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return function_exists( 'get_field' ) && function_exists( 'acf_get_field_groups' );
	}

	/**
	 * Fields that could hold a list of categories, for the settings dropdown.
	 *
	 * @return array Map of field name => label.
	 */
	public static function get_selectable_fields() {
		$fields = array();

		if ( ! self::is_active() ) {
			return $fields;
		}

		$groups = acf_get_field_groups();

		if ( ! is_array( $groups ) ) {
			return $fields;
		}

		/**
		 * Filter the ACF field types offered as a category source.
		 *
		 * @param string[] $types Field types.
		 */
		$types = apply_filters(
			'wppdf_acf_field_types',
			array( 'taxonomy', 'select', 'checkbox', 'radio', 'button_group', 'text', 'textarea', 'number' )
		);

		foreach ( $groups as $group ) {
			$group_fields = acf_get_fields( $group );

			if ( ! is_array( $group_fields ) ) {
				continue;
			}

			foreach ( $group_fields as $field ) {
				if ( empty( $field['name'] ) || ! in_array( $field['type'], $types, true ) ) {
					continue;
				}

				$fields[ $field['name'] ] = sprintf(
					'%s — %s (%s)',
					isset( $group['title'] ) ? $group['title'] : '',
					isset( $field['label'] ) ? $field['label'] : $field['name'],
					$field['name']
				);
			}
		}

		ksort( $fields );

		return $fields;
	}

	/**
	 * The raw value of the configured field on a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name, defaults to the configured one.
	 * @return mixed Raw value, null when there is nothing to read.
	 */
	public static function get_raw_value( $post_id, $field = '' ) {
		$field = '' !== $field ? $field : (string) WPPDF_Settings::get( 'acf_category_field' );

		if ( '' === $field || ! $post_id ) {
			return null;
		}

		if ( self::is_active() ) {
			$value = get_field( $field, $post_id );

			if ( null !== $value && '' !== $value && array() !== $value ) {
				return $value;
			}
		}

		// Without ACF, or when it returns nothing, the plain meta still works.
		$value = get_post_meta( $post_id, $field, true );

		return '' === $value ? null : $value;
	}

	/**
	 * The category term IDs a post selects.
	 *
	 * @param int    $post_id Post ID, defaults to the current one.
	 * @param string $field   Field name, defaults to the configured one.
	 * @return int[] Term IDs, empty when the field is unset or unusable.
	 */
	public static function get_term_ids( $post_id = 0, $field = '' ) {
		$post_id = $post_id ? absint( $post_id ) : get_the_ID();
		$value   = self::get_raw_value( $post_id, $field );

		if ( null === $value ) {
			return array();
		}

		$terms = array();

		foreach ( self::flatten( $value ) as $item ) {
			$term_id = self::resolve_term( $item );

			if ( $term_id && ! in_array( $term_id, $terms, true ) ) {
				$terms[] = $term_id;
			}
		}

		/**
		 * Filter the category term IDs read from a page's field.
		 *
		 * @param int[] $terms   Term IDs.
		 * @param int   $post_id Post ID.
		 * @param mixed $value   Raw field value.
		 */
		return apply_filters( 'wppdf_page_term_ids', $terms, $post_id, $value );
	}

	/**
	 * Reduce whatever the field returned to a flat list of scalars.
	 *
	 * ACF hands back term objects, arrays of IDs, arrays of slugs or a plain
	 * string depending on the field type and its return format, so all of
	 * those have to be accepted.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	protected static function flatten( $value ) {
		if ( $value instanceof WP_Term ) {
			return array( $value );
		}

		if ( is_object( $value ) ) {
			return array( $value );
		}

		if ( is_string( $value ) ) {
			// A text field commonly holds "reports, catalogues".
			return array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
		}

		if ( is_scalar( $value ) ) {
			return array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $item ) {
			foreach ( self::flatten( $item ) as $scalar ) {
				$out[] = $scalar;
			}
		}

		return $out;
	}

	/**
	 * Turn one value into a term ID of a taxonomy the documents use.
	 *
	 * @param mixed $item Term object, ID, slug or name.
	 * @return int Term ID, 0 when it matches nothing.
	 */
	protected static function resolve_term( $item ) {
		$taxonomies = WPPDF_Post_Type::get_document_taxonomies();

		if ( empty( $taxonomies ) ) {
			return 0;
		}

		if ( $item instanceof WP_Term ) {
			return in_array( $item->taxonomy, $taxonomies, true ) ? (int) $item->term_id : 0;
		}

		if ( is_object( $item ) && isset( $item->term_id ) ) {
			$item = (int) $item->term_id;
		}

		if ( is_numeric( $item ) ) {
			$term_id = absint( $item );

			foreach ( $taxonomies as $taxonomy ) {
				$term = get_term( $term_id, $taxonomy );

				if ( $term && ! is_wp_error( $term ) ) {
					return (int) $term->term_id;
				}
			}

			return 0;
		}

		if ( ! is_string( $item ) || '' === $item ) {
			return 0;
		}

		foreach ( $taxonomies as $taxonomy ) {
			foreach ( array( 'slug', 'name' ) as $field ) {
				$term = get_term_by( $field, $item, $taxonomy );

				if ( $term && ! is_wp_error( $term ) ) {
					return (int) $term->term_id;
				}
			}
		}

		return 0;
	}
}
