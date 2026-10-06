jQuery(function ($) {
  function isMobileLayout() {
    return window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  }

  var i18n = (window.QNCalendarAjax && QNCalendarAjax.i18n) || {};

  function message(key, fallback) {
    return i18n[key] || fallback;
  }

  function setExpanded($box, expanded) {
    $box.toggleClass('is-open', expanded);
    $box.find('.calendar-day').attr('aria-expanded', expanded ? 'true' : 'false');
  }

  function closeOtherBoxes($root, $except) {
    $root.find('.event-day-box.is-open').each(function () {
      var $box = $(this);
      if (!$except || !$box.is($except)) {
        setExpanded($box, false);
      }
    });
  }

  function clearMobileSelection($root, $except) {
    $root.find('.event-day-box.is-mobile-selected').each(function () {
      var $box = $(this);
      if (!$except || !$box.is($except)) {
        $box.removeClass('is-mobile-selected');
        $box.find('.calendar-day').attr('aria-expanded', 'false');
      }
    });
  }

  function ensureMobilePanel($table) {
    var $panel = $table.next('.qncc-mobile-detail');

    if ($panel.length) {
      return $panel;
    }

    $panel = $(
      '<div class="qncc-mobile-detail" hidden>' +
        '<div class="qncc-mobile-detail-heading"></div>' +
        '<div class="qncc-mobile-detail-body"></div>' +
      '</div>'
    );

    $table.after($panel);
    return $panel;
  }

  function closeMobilePanel($panel) {
    if (!$panel || !$panel.length) return;

    var $root = $panel.closest('.qn-content-calendar');
    $panel.removeClass('is-open').attr('hidden', true).removeData('date-key');
    $panel.find('.qncc-mobile-detail-heading').empty();
    $panel.find('.qncc-mobile-detail-body').empty();
    clearMobileSelection($root);
  }

  function closeAllMobilePanels() {
    $('.qncc-mobile-detail.is-open').each(function () {
      closeMobilePanel($(this));
    });
  }

  function buildMobileHeading($box) {
    var $dayControl = $box.find('.calendar-day');
    var day = parseInt($dayControl.data('day'), 10);
    var month = parseInt($dayControl.data('month'), 10);
    var year = parseInt($dayControl.data('year'), 10);
    var total = parseInt($box.find('.post-count').data('total'), 10) || 0;

    return year + '年' + month + '月' + day + '日 · ' + total + message('items', '条内容');
  }

  function openMobilePanel($box) {
    var $root = $box.closest('.qn-content-calendar');
    var $table = $box.closest('.custom-calendar');
    var $panel = ensureMobilePanel($table);
    var $heading = $panel.find('.qncc-mobile-detail-heading');
    var $body = $panel.find('.qncc-mobile-detail-body');
    var $tooltip = $box.find('.calendar-tooltip');
    var $dayControl = $box.find('.calendar-day');
    var dateKey = [
      $dayControl.data('year'),
      $dayControl.data('month'),
      $dayControl.data('day')
    ].join('-');

    // 如果再次点击当前选中的日期，直接收起。
    if ($box.hasClass('is-mobile-selected') && $panel.hasClass('is-open')) {
      closeMobilePanel($panel);
      return;
    }

    // 同一日历组件中只保留一个移动端详情面板。
    closeAllMobilePanels();
    clearMobileSelection($root, $box);
    closeOtherBoxes($root);

    $box.addClass('is-mobile-selected');
    $box.find('.calendar-day').attr('aria-expanded', 'true');
    $heading.text(buildMobileHeading($box));
    $body.html(
      '<div class="tooltip-loading">' +
        message('loading', '加载中...') +
      '</div>'
    );
    $panel.data('date-key', dateKey).removeAttr('hidden').addClass('is-open');

    loadTooltip($box, function () {
      // 快速切换日期时，旧请求不得覆盖当前已选择日期的内容。
      if ($panel.data('date-key') !== dateKey || !$box.hasClass('is-mobile-selected')) {
        return;
      }
      var $inner = $tooltip.find('.tooltip-inner');

      if ($inner.length) {
        $body.html($inner.html());
      } else {
        $body.html(
          '<div class="tooltip-empty">' +
            message('empty', '当日没有记录') +
          '</div>'
        );
      }
    });
  }

  /**
   * 加载某个格子的 tooltip 内容。
   * 成功后直接缓存在当前 DOM 节点，后续 hover / click 不再重复请求。
   * callback 用于移动端详情面板复用同一份 Ajax 数据。
   */
  function loadTooltip($box, callback) {
    var $tooltip = $box.find('.calendar-tooltip');

    if ($tooltip.data('loaded')) {
      if (typeof callback === 'function') callback();
      return;
    }

    if ($tooltip.data('loading')) {
      if (typeof callback === 'function') {
        $tooltip.one('qncc:loaded qncc:failed', callback);
      }
      return;
    }

    var $dayControl = $box.find('.calendar-day');
    var day = parseInt($dayControl.data('day'), 10);
    var month = parseInt($dayControl.data('month'), 10);
    var year = parseInt($dayControl.data('year'), 10);

    if (!day || !month || !year) return;

    var $root = $box.closest('.qn-content-calendar');
    var postType = $root.data('post-type') || 'quicknotes';
    var taxonomy = $root.data('taxonomy') || '';
    var termIds = $root.data('term-ids') || '';

    if (typeof callback === 'function') {
      $tooltip.one('qncc:loaded qncc:failed', callback);
    }

    $tooltip.data('loading', true).html(
      '<div class="tooltip-inner"><div class="tooltip-loading">' +
        message('loading', '加载中...') +
        '</div></div>'
    );

    $.ajax({
      url: QNCalendarAjax.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'qn_calendar_get_quicknotes',
        nonce: QNCalendarAjax.nonce,
        day: day,
        month: month,
        year: year,
        post_type: postType,
        taxonomy: taxonomy,
        term_ids: termIds
      }
    })
      .done(function (resp) {
        if (!resp || !resp.success) {
          $tooltip.html(
            '<div class="tooltip-inner"><div class="tooltip-error">' +
              message('failed', '加载失败') +
              '</div></div>'
          );
          $tooltip.trigger('qncc:failed');
          return;
        }

        if (resp.data && resp.data.html) {
          $tooltip.html('<div class="tooltip-inner">' + resp.data.html + '</div>');
        } else {
          $tooltip.html(
            '<div class="tooltip-inner"><div class="tooltip-empty">' +
              message('empty', '当日没有记录') +
              '</div></div>'
          );
        }

        $tooltip.data('loaded', true);
        $tooltip.trigger('qncc:loaded');
      })
      .fail(function () {
        $tooltip.html(
          '<div class="tooltip-inner"><div class="tooltip-error">' +
            message('error', '请求出错') +
            '</div></div>'
        );
        $tooltip.trigger('qncc:failed');
      })
      .always(function () {
        $tooltip.data('loading', false);
      });
  }

  // 桌面端 hover 加载；内容显示仍由 CSS 控制。
  $(document).on('mouseenter', '.event-day-box.has-post', function () {
    if (isMobileLayout()) return;
    loadTooltip($(this));
  });

  // 点击日期：桌面端固定 tooltip；手机端使用日历下方详情面板。
  $(document).on('click', '.event-day-box.has-post .calendar-day', function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $box = $(this).closest('.event-day-box');

    if (isMobileLayout()) {
      openMobilePanel($box);
      return;
    }

    var $root = $box.closest('.qn-content-calendar');
    var willOpen = !$box.hasClass('is-open');

    loadTooltip($box);
    closeOtherBoxes($root, $box);
    clearMobileSelection($root);
    setExpanded($box, willOpen);
  });

  // 点击详情内容本身不关闭，链接可以正常访问。
  $(document).on('click', '.calendar-tooltip, .qncc-mobile-detail', function (e) {
    e.stopPropagation();
  });

  // 点击页面空白处关闭已展开的 tooltip / 手机详情面板。
  $(document).on('click', function () {
    $('.event-day-box.is-open').each(function () {
      setExpanded($(this), false);
    });
    closeAllMobilePanels();
  });

  // 键盘 ESC 关闭，并把焦点还给当前日期按钮。
  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;

    $('.event-day-box.is-open, .event-day-box.is-mobile-selected').each(function () {
      var $box = $(this);
      setExpanded($box, false);
      $box.removeClass('is-mobile-selected');
      $box.find('.calendar-day').attr('aria-expanded', 'false').trigger('focus');
    });

    $('.qncc-mobile-detail.is-open')
      .removeClass('is-open')
      .attr('hidden', true)
      .find('.qncc-mobile-detail-heading, .qncc-mobile-detail-body')
      .empty();
  });

  /**
   * 月份导航按钮：上一月 / 下一月。
   */
  $(document).on('click', '.qncc-nav-btn', function () {
    var $btn = $(this);
    var $root = $btn.closest('.qn-content-calendar');

    if ($btn.prop('disabled') || $root.hasClass('is-loading')) return;

    var months = parseInt($root.data('months'), 10) || 1;
    var startYear = parseInt($root.data('start-year'), 10);
    var startMonth = parseInt($root.data('start-month'), 10);
    var currentYear = parseInt($root.data('current-year'), 10);
    var currentMonth = parseInt($root.data('current-month'), 10);
    var postType = $root.data('post-type') || 'quicknotes';
    var taxonomy = $root.data('taxonomy') || '';
    var termIds = $root.data('term-ids') || '';

    if (!startYear || !startMonth) return;

    // 切换月份前明确关闭当前桌面 / 手机详情状态。
    closeOtherBoxes($root);
    clearMobileSelection($root);
    $root.find('.qncc-mobile-detail').remove();

    var direction = $btn.hasClass('qncc-prev-month') ? -1 : 1;
    var newMonth = startMonth + direction;
    var newYear = startYear;

    if (newMonth < 1) {
      newMonth += 12;
      newYear--;
    } else if (newMonth > 12) {
      newMonth -= 12;
      newYear++;
    }

    if (
      direction === 1 &&
      (newYear > currentYear ||
        (newYear === currentYear && newMonth > currentMonth))
    ) {
      return;
    }

    $root.addClass('is-loading').attr('aria-busy', 'true');
    $root.find('.qncc-nav-btn').prop('disabled', true);

    $.ajax({
      url: QNCalendarAjax.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'qn_calendar_render_months',
        nonce: QNCalendarAjax.nonce,
        start_year: newYear,
        start_month: newMonth,
        months: months,
        post_type: postType,
        taxonomy: taxonomy,
        term_ids: termIds
      }
    })
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data) return;

        if (typeof resp.data.html !== 'undefined') {
          $root.find('.qncc-calendars').html(resp.data.html);
        }
        if (resp.data.label) {
          $root.find('.qncc-current-label').text(resp.data.label);
        }

        var actualYear = parseInt(resp.data.start_year, 10) || newYear;
        var actualMonth = parseInt(resp.data.start_month, 10) || newMonth;
        $root.data('start-year', actualYear);
        $root.data('start-month', actualMonth);
      })
      .always(function () {
        var actualYear = parseInt($root.data('start-year'), 10);
        var actualMonth = parseInt($root.data('start-month'), 10);

        $root.removeClass('is-loading').attr('aria-busy', 'false');
        $root.find('.qncc-prev-month').prop('disabled', false);
        $root
          .find('.qncc-next-month')
          .prop(
            'disabled',
            actualYear === currentYear && actualMonth === currentMonth
          );
      });
  });

  // 从桌面缩到手机或反向切换时，清理另一种交互留下的打开状态。
  if (window.matchMedia) {
    var mobileMedia = window.matchMedia('(max-width: 767px)');
    var handleLayoutChange = function () {
      $('.event-day-box.is-open').each(function () {
        setExpanded($(this), false);
      });
      closeAllMobilePanels();
    };

    if (typeof mobileMedia.addEventListener === 'function') {
      mobileMedia.addEventListener('change', handleLayoutChange);
    } else if (typeof mobileMedia.addListener === 'function') {
      mobileMedia.addListener(handleLayoutChange);
    }
  }

  $('.qn-content-calendar').each(function () {
    var $root = $(this);
    var startYear = parseInt($root.data('start-year'), 10);
    var startMonth = parseInt($root.data('start-month'), 10);
    var currentYear = parseInt($root.data('current-year'), 10);
    var currentMonth = parseInt($root.data('current-month'), 10);

    $root.attr('aria-busy', 'false');
    $root
      .find('.qncc-next-month')
      .prop(
        'disabled',
        startYear === currentYear && startMonth === currentMonth
      );
  });
});
