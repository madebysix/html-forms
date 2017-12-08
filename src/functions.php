<?php

use HTML_Forms\Form;
use HTML_Forms\Submission;

/**
 * @param $form_id_or_slug int|string
 * @return Form
 * @throws Exception
 */
function hf_get_form( $form_id_or_slug ) {

    if( is_numeric( $form_id_or_slug ) ) {
        $post = get_post( $form_id_or_slug );

        if( ! $post || $post->post_type !== 'html-form' ) {
            throw new Exception( "Invalid form ID" );
        }
    } else {
        $posts = get_posts(
            array(
                'post_type' => 'html-form',
                'name' => $form_id_or_slug,
                'post_status' => 'publish',
                'numberposts' => 1,
            )
        );

        if( empty( $posts ) ) {
            throw new Exception( 'Invalid form slug' );
        }
        $post = $posts[0];
    }

    static $default_messages;
    if( $default_messages === null ) {
        $default_messages = array(
            'success' => __('Thank you! We will be in touch soon.', 'html-forms'),
            'invalid_email' => __( 'Sorry, that email address looks invalid.', 'html-forms' ),
            'required_field_missing' => __( "Please fill in the required fields.", "html-forms" ),
            'error' => __( 'Oops. An error occurred.', 'html-forms' ),
        );
    }

    static $default_settings = array(
        'hide_after_success' => 0,
        'redirect_url' => '',
        'required_fields' =>'',
        'email_fields' => '',
    );

    $post_meta = get_post_meta( $post->ID );

    $settings = array();
    if( ! empty( $post_meta['_hf_settings'][0] ) ) {
        $settings = (array) maybe_unserialize( $post_meta['_hf_settings'][0] );
    }
    $settings = array_merge( $default_settings, $settings );

    $messages = array();
    foreach( $post_meta as $meta_key => $meta_values ) {
        if( strpos( $meta_key, 'hf_message_' ) === 0 ) {
            $message_key = substr( $meta_key, strlen( 'hf_message_' ) );
            $messages[$message_key] = (string) $meta_values[0];
        }
    }
    $messages = array_merge( $default_messages, $messages );

    $form = new Form( $post->ID );
    $form->title = $post->post_title;
    $form->slug = $post->post_name;
    $form->markup = $post->post_content;
    $form->settings = $settings;
    $form->messages = $messages;
    return $form;
}

/**
 * @param $form_id
 * @param array $args
 * @return Submission[]
 */
function hf_get_form_submissions( $form_id, array $args = array() ) {
    $default_args = array(
        'offset' => 0,
        'limit' => 1000,
    );
    $args = array_merge( $default_args, $args );

    global $wpdb;
    $table = $wpdb->prefix .'hf_submissions';
    $results = $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$table} s WHERE s.form_id = %d ORDER BY s.submitted_at DESC LIMIT %d, %d;", $form_id, $args['offset'], $args['limit'] ), OBJECT_K );
    $submissions = array();
    foreach( $results as $key => $object ) {
        $submission = Submission::from_object( $object );
        $submissions[$key] = $submission;
    }
    return $submissions;
}

/**
 * @param int $submission_id
 * @return Submission
 */
function hf_get_form_submission( $submission_id ) {
    global $wpdb;
    $table = $wpdb->prefix .'hf_submissions';
    $object = $wpdb->get_row( $wpdb->prepare( "SELECT s.* FROM {$table} s WHERE s.id = %d;", $submission_id ), OBJECT );
    $submission = Submission::from_object( $object );
    return $submission;
}
/**
 * @return array
 */
function hf_get_settings() {
    static $default_settings = array(
        'load_stylesheet' => 0,
    );

    $settings = get_option( 'hf_settings', array() );
    return array_merge( $default_settings, $settings );
}

/**
* Get element from array, allows for dot notation eg: "foo.bar"
*
* @param array $array
* @param string $key
* @param mixed $default
* @return mixed
*/
function hf_array_get( $array, $key, $default = null ) {
    if ( is_null( $key ) ) {
        return $array;
    }

    if ( isset( $array[$key] ) ) {
        return $array[$key];
    }

    foreach (explode( '.', $key ) as $segment) {
        if ( ! is_array( $array ) || ! array_key_exists( $segment, $array ) ) {
            return $default;
        }

        $array = $array[$segment];
    }

    return $array;
}

/**
 * Processes template tags like {{user.user_email}}
 *
 * @param string $template
 *
 * @return string
 */
function hf_template( $template ) {
    $replacers = array(
        'user' => function( $prop ) {
            if( ! is_user_logged_in() ) {
                return '';
            }

            $user = wp_get_current_user();
            return isset( $user->{$prop} ) ? $user->{$prop} : '';
         },
         'post' => function( $prop ) {
            global $post;

            if( ! $post instanceof WP_Post || ! isset( $post->{$prop} ) ) {
                return '';
            }

            return $post->{$prop};
         },
         'url_params' => function( $key ) {
            if( ! isset( $_GET[ $key] ) ) {
                return '';
            }

            return esc_attr( strip_tags( $_GET[$key] ) );
         }
    ); 

    /**
    * Filters the replacement logic applied to the form content.
    *
    * Can be used to add scalar replacement values or more advanced function replacers accepting a parameter.
    *
    * @param array $replacers
    */
    $replacers = apply_filters( 'hf_template_replacers', $replacers );

    $template = preg_replace_callback( '/\{\{ *(\w+)(?:\.([\w\.]+))? *(?:\|\| *(\w+))? *\}\}/', function( $matches ) use ( $replacers ) {
        $replacer = $matches[1];
        $param = ! isset( $matches[2] ) ? "" : $matches[2];
        $default = ! isset( $matches[3] ) ? "" : $matches[3];
        $value = "";

        // do not change anything if we have no replacer with that key, could be custom user logic or another plugin.
        if( ! isset( $replacers[ $replacer] ) ) {
            return $matches[0];
        }

        $replacer = $replacers[$replacer];
        $value = is_callable( $replacer ) ? call_user_func_array( $replacer, array( $param ) ) : $replacer;
        return ! empty( $value ) ? $value : $default;
    }, $template );

    return $template;    
}

/**
 * @param string $string
 * @param array $data
 *
 * @return string
 */
function hf_replace_data_variables( $string, $data = array() ) {
    $string = preg_replace_callback( '/\[([a-zA-Z0-9\-\._]+)\]/', function( $matches ) use ( $data ) {
        $key = $matches[1];
        $replacement = hf_array_get( $data, $key, '' );
        return $replacement;
    }, $string );
    return $string;
}
