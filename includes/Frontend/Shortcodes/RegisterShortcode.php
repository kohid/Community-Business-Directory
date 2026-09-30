<?php

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class RegisterShortcode
{
  public function render(array $atts): string
  {
    $atts = shortcode_atts(['redirect' => ''], $atts);

    // Check if user submitted & already owns a listing
    $message = '';
    $user_id = get_current_user_id();

    $categories = get_terms(['taxonomy' => 'cbd_category', 'hide_empty' => false]);
    $no_cats = is_wp_error($categories) || empty($categories);

    ob_start(); ?>
    <div class="cbd-wrap">
      <?php if ($message) echo '<div class="cbd-notice cbd-notice-success">' . esc_html($message) . '</div>'; ?>

      <div class="cbd-register-form">
        <h2 class="cbd-form-title"><?php esc_html_e('List Your Business', 'community-business-directory'); ?></h2>
        <p class="cbd-form-sub"><?php esc_html_e('Fill in the details below to add your business to the directory.', 'community-business-directory'); ?></p>

        <?php if (! is_user_logged_in()) : ?>
          <div class="cbd-notice cbd-notice-info">
            <?php printf(
              wp_kses(__('Please <a href="%s">sign in or register</a> to submit your business.', 'community-business-directory'), ['a' => ['href' => []]]),
              esc_url(wp_login_url(get_permalink()))
            ); ?>
          </div>
        <?php else : ?>

          <form id="cbd-register-form" class="cbd-form" enctype="multipart/form-data">
            <?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
            <input type="hidden" name="action" value="cbd_register_business">
            <?php if ($atts['redirect']) : ?><input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>"><?php endif; ?>

            <div class="cbd-form-section">
              <h3><?php esc_html_e('Basic Information', 'community-business-directory'); ?></h3>
              <div class="cbd-form-row">
                <label for="cbd_biz_name"><?php esc_html_e('Business Name', 'community-business-directory'); ?> <span class="req">*</span></label>
                <input type="text" id="cbd_biz_name" name="biz_name" required placeholder="<?php esc_attr_e('Your Business Name', 'community-business-directory'); ?>">
              </div>
              <div class="cbd-form-row">
                <label for="cbd_biz_description"><?php esc_html_e('Description', 'community-business-directory'); ?> <span class="req">*</span></label>
                <textarea id="cbd_biz_description" name="biz_description" rows="5" required placeholder="<?php esc_attr_e('Tell people about your business…', 'community-business-directory'); ?>"></textarea>
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label for="cbd_biz_category"><?php esc_html_e('Category', 'community-business-directory'); ?> <span class="req">*</span></label>
                  <select id="cbd_biz_category" name="biz_category" required>
                    <option value=""><?php esc_html_e('— Select Category —', 'community-business-directory'); ?></option>
                    <?php if (isset($no_cats) && $no_cats) : ?>
                      <option value="" disabled><?php esc_html_e('(No categories yet — admin must add them)', 'community-business-directory'); ?></option>
                    <?php elseif (! is_wp_error($categories)) : ?>
                      <?php foreach ($categories as $cat) : ?>
                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html(\cbd_label($cat->name)); ?></option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_biz_tagline"><?php esc_html_e('Tagline / Slogan', 'community-business-directory'); ?></label>
                  <input type="text" id="cbd_biz_tagline" name="biz_tagline" placeholder="<?php esc_attr_e('Short tagline (optional)', 'community-business-directory'); ?>">
                </div>
              </div>
            </div>

            <div class="cbd-form-section">
              <h3><?php esc_html_e('Contact Details', 'community-business-directory'); ?></h3>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label for="cbd_biz_email"><?php esc_html_e('Contact Email', 'community-business-directory'); ?> <span class="req">*</span></label>
                  <input type="email" id="cbd_biz_email" name="biz_email" required placeholder="hello@yourbusiness.com">
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_biz_phone"><?php esc_html_e('Phone Number', 'community-business-directory'); ?></label>
                  <input type="tel" id="cbd_biz_phone" name="biz_phone" placeholder="+44 1234 567890">
                </div>
              </div>
              <div class="cbd-form-row">
                <label for="cbd_biz_website"><?php esc_html_e('Website', 'community-business-directory'); ?></label>
                <input type="url" id="cbd_biz_website" name="biz_website" placeholder="https://www.yourbusiness.com">
              </div>
            </div>

            <div class="cbd-form-section">
              <h3><?php esc_html_e('Address', 'community-business-directory'); ?></h3>
              <div class="cbd-form-row">
                <label for="cbd_biz_address"><?php esc_html_e('Street Address', 'community-business-directory'); ?></label>
                <input type="text" id="cbd_biz_address" name="biz_address" placeholder="<?php esc_attr_e('123 High Street', 'community-business-directory'); ?>">
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label for="cbd_biz_city"><?php esc_html_e('City', 'community-business-directory'); ?></label>
                  <input type="text" id="cbd_biz_city" name="biz_city" placeholder="<?php esc_attr_e('City', 'community-business-directory'); ?>">
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_biz_postcode"><?php esc_html_e('Postcode / ZIP', 'community-business-directory'); ?></label>
                  <input type="text" id="cbd_biz_postcode" name="biz_postcode" placeholder="IV1 1AB">
                </div>
              </div>
            </div>

            <div class="cbd-form-section">
              <h3><?php esc_html_e('Social Media', 'community-business-directory'); ?></h3>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label for="cbd_social_facebook"><?php esc_html_e('Facebook', 'community-business-directory'); ?></label>
                  <input type="url" id="cbd_social_facebook" name="social_facebook" placeholder="https://facebook.com/yourbusiness">
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_social_instagram"><?php esc_html_e('Instagram', 'community-business-directory'); ?></label>
                  <input type="url" id="cbd_social_instagram" name="social_instagram" placeholder="https://instagram.com/yourbusiness">
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_social_twitter"><?php esc_html_e('X / Twitter', 'community-business-directory'); ?></label>
                  <input type="url" id="cbd_social_twitter" name="social_twitter" placeholder="https://x.com/yourbusiness">
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_social_linkedin"><?php esc_html_e('LinkedIn', 'community-business-directory'); ?></label>
                  <input type="url" id="cbd_social_linkedin" name="social_linkedin" placeholder="https://linkedin.com/company/yourbusiness">
                </div>
              </div>
            </div>

            <div class="cbd-form-section">
              <h3><?php esc_html_e('Opening Hours', 'community-business-directory'); ?></h3>
              <div class="cbd-hours-grid">
                <?php foreach (
                  [
                    'monday' => 'Monday',
                    'tuesday' => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday' => 'Thursday',
                    'friday' => 'Friday',
                    'saturday' => 'Saturday',
                    'sunday' => 'Sunday'
                  ] as $key => $day
                ) : ?>
                  <div class="cbd-hours-row">
                    <label class="cbd-hours-day">
                      <input type="checkbox" name="hours[<?php echo esc_attr($key); ?>][open]" value="1" checked>
                      <?php echo esc_html($day); ?>
                    </label>
                    <input type="time" name="hours[<?php echo esc_attr($key); ?>][from]" value="09:00">
                    <span><?php esc_html_e('to', 'community-business-directory'); ?></span>
                    <input type="time" name="hours[<?php echo esc_attr($key); ?>][to]" value="17:00">
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="cbd-form-section">
              <h3><?php esc_html_e('Images', 'community-business-directory'); ?></h3>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label for="cbd_biz_logo"><?php esc_html_e('Business Logo', 'community-business-directory'); ?></label>
                  <input type="file" id="cbd_biz_logo" name="biz_logo" accept="image/*" data-crop-aspect="1" data-crop-w="400" data-crop-h="400">
                  <small><?php esc_html_e('Recommended: Square, 400×400px, PNG/JPG', 'community-business-directory'); ?></small>
                </div>
                <div class="cbd-form-row">
                  <label for="cbd_biz_cover"><?php esc_html_e('Cover Image', 'community-business-directory'); ?></label>
                  <input type="file" id="cbd_biz_cover" name="biz_cover" accept="image/*" data-crop-aspect="3" data-crop-w="1200" data-crop-h="400">
                  <small><?php esc_html_e('Recommended: 1200×400px, JPG/PNG', 'community-business-directory'); ?></small>
                </div>
              </div>
            </div>

            <div class="cbd-form-actions">
              <button type="submit" class="cbd-btn cbd-btn-primary" id="cbd-register-btn">
                <span class="btn-text"><?php esc_html_e('Submit Listing', 'community-business-directory'); ?></span>
                <span class="btn-loading" style="display:none;"><?php esc_html_e('Submitting…', 'community-business-directory'); ?></span>
              </button>
              <p class="cbd-form-note"><?php esc_html_e('* Required fields. Listings may be reviewed before going live.', 'community-business-directory'); ?></p>
            </div>

            <div id="cbd-register-msg" class="cbd-form-msg" style="display:none;"></div>
          </form>
        <?php endif; ?>
      </div>
    </div>
<?php
    return ob_get_clean();
  }
}
