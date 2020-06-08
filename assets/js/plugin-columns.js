jQuery( document ).ready(function($) {
    "use strict";    
    
    var select2Container = $('.pc-categories-input'),
    categoryEditSelect = $('select', select2Container),
    pluginTable = $('.wp-list-table.widefat.plugins'),
    stickyEl = $('thead', pluginTable),
    columnsOptions = $('.pc-tab-columns'),
    optionsDialog = $('.pc-options-dialog'),    
    optionsTabs = $('.pc-options-tabs > a', optionsDialog),    
    modalOverlay = $('.pc-modal-overlay'),
    catItemTemplate = wp.template( 'category-option-item' ),    
    categoryFeatures = {},    
    currentCat = false,    
    catChanged = false;

    categoryFeatures.pinned = getArray(pcvars.pinnedCategories);
    categoryFeatures.hidden = getArray(pcvars.hiddenCategories);
    categoryFeatures.warning = getArray(pcvars.warningCategories);
    categoryFeatures.noupdate = getArray(pcvars.noupdateCategories);

    // Sticky columns header
    if ( pcvars.stickyHeader && !$('body').is('.mobile') )  {
        var stickyOffset = pluginTable.offset().top + 50;

        setStickyColumnWidth();
        
        $(window).scroll(function(){
            var scroll = $(window).scrollTop();
            if (scroll >= stickyOffset) {                
                stickyEl.addClass('ptfixed');
            }
            else {
                stickyEl.removeClass('ptfixed');
            }
        });

        $('.hide-column-tog').on('change', function(e){
            setStickyColumnWidth();            
        });
    }    

    $('#name.column-name').html( wp.template( 'plugin-column-header' ) );    

    if ( categoryFeatures.pinned.length > 0 ) {
        $('#menu-plugins a[href*="-plugin-columns-category-page"]').each(function(index, link){
            var category = $(this).attr('href').split('=')[1].replace('-plugin-columns-category-page', '');
            if ( categoryFeatures.pinned.indexOf( category.replace(/\+/g, ' ') ) != -1 ) {                               
                $(this).attr('href', pcvars.pluginsUrl + '?' + 'category_name='+category );
            }        
        });
    }        

    categoryEditSelect.select2({
        tags: true,
        tokenSeparators: [',']
    }).on('select2:update', function (e) {
        if ( currentCat ) {            
            var val = categoryEditSelect.val();            
            var plugin = select2Container.data('plugin');
            var oldCats = $('.pc-cat-edit', currentCat).data('cats');
            var html = '';
            var categories = '';
            
            if ( val ) {
                $.each(val.sort(), function( index, value ) {
                    value = $("<div/>").html( $.trim(value) ).text();
                    if ( index > 0 ) {
                        html += ', ';
                        categories += ',';
                    }
                    var searchParams = new URLSearchParams(location.search);
                    searchParams.set('category_name', value);                    
                    html += '<a href="'+pcvars.pluginsUrl+'?'+searchParams.toString()+'">'+value+'</a>';
                    categories += value;
                });
            }
            
            if ( oldCats !== categories ) {
                ajaxPost( { plugin: plugin, categories: categories }, function(data) {
                    if ( data.update && data.update === 'success' ) {
                        $('.pc-cat-values', currentCat).html(html);
                        $('.pc-cat-edit', currentCat).data('cats', categories);                        
                    }
                    if ( data.newCats ) {                        
                        pcvars.categories = (pcvars.categories?pcvars.categories+',':'') + data.newCats;                        
                        catChanged = true;
                        $.each(data.newCats.split(','), function( index, value ) {
                            $('option[value="'+value+'"]', select2Container).removeAttr('data-select2-id data-select2-tag');                            
                        });
                        $(this).trigger('change');
                    }
                });
            }
        }
    });

    $(document).on('click', '.pc-cat-edit', function(){
        if ( $(this).is('.dashicons-yes') ) {
            $(this).removeClass('dashicons-yes').css('display', '');            
            select2Container.hide();
            $(this).siblings('.pc-cat-cancel').css('display', '');
            categoryEditSelect.trigger('select2:update');
            modalOverlay.hide();
        }
        else {           
            var position = $(this).parent().position();            
            var cHeight = $(this).siblings('.pc-cat-values').height();
            if ( cHeight === 0 ) cHeight = 15;
            var topOffset = position.top + cHeight + 10;
            var leftOffset = position.left - 68;            

            if ( position.left + 200 >= $('#wpbody').width() ) {            
                leftOffset = $('#wpbody').width() - 220;            
            }

            if ( select2Container.is(':visible') && select2Container.data('plugin') === $(this).data('plugin') ) {
                select2Container.hide();
                $(this).css('display', '');
                modalOverlay.hide();
                currentCat = false;
            }
            else {
                currentCat = $(this).parent();
                var categories = $(this).data('cats').split(',');
                $('option', categoryEditSelect).each( function(key, option) {                
                    if ( categories.indexOf( option.value ) !== -1 ) {
                        $(this).prop('selected', true);
                    }
                    else {
                        $(this).prop('selected', false);
                    }                
                });
                categoryEditSelect.trigger('change');            

                select2Container.data('plugin', $(this).data('plugin'));
                $(this).addClass('dashicons-yes');                
                $(this).siblings('.pc-cat-cancel').css('display', 'inline-block');
                select2Container.css({ top: topOffset, left: leftOffset }).show();
                $('.pc-cat-edit').css('display', '');
                $(this).css('display', 'inline-block');
                modalOverlay.show();
            }
        }
    });

    $(document).on('contextmenu', '.select2-selection__choice', function(e){        
        e.preventDefault();        
        var categoryToDelete = $(this).attr('title');
        ajaxPost( { category_delete: categoryToDelete }, function(data) {        
            if ( data.deleteCategory ) {
                location.reload();
            }
        });
    });

    $('.plugins-php #bulk-action-form').on('submit', function(e){        
        if ( $('#bulk-action-selector-top', this).val() === 'edit_categories'
            || $('#bulk-action-selector-bottom', this).val() === 'edit_categories' ) {
            e.preventDefault();
            $(document).scrollTop( $(this).scrollTop() );
            var form = $('#bulk-action-form');
            var formValues = getForm(form);
            var template = wp.template( 'bulk-inline-edit-form' );            
            var plugins = formValues['checked[]'].split(',');
            var categories = getCategories();            
            var params = { plugins: plugins, categories: categories };
            $('.wp-list-table.plugins tbody#the-list').prepend( template( params ) );            
        }
        else if ( pcvars.pluginView === 'imported' || pcvars.pluginView === 'trash' ) {
            $('[name="plugin_status"]', form).val(pcvars.pluginView);
        }
    });

    $('.plugins-php #bulk-action-form').on('click', '.inline-edit-row-plugins .ntdelbutton', function(e){
        $(this).parent().remove();
    });

    $('.plugins-php #bulk-action-form').on('click', '#bulk_edit', function(e){
        e.preventDefault();
        var form = $('#bulk-action-form');
        var formValues = getForm(form);        
        var plugins = [];
        $('.inline-edit-row-plugins #bulk-titles > div').each(function(index, el){ plugins.push( $(el).attr('id') ); });        
        var categories_add = formValues['plugin_category_add[]']?formValues['plugin_category_add[]'].split(','):'';
        var categories_remove = formValues['plugin_category_remove[]']?formValues['plugin_category_remove[]'].split(','):'';
        ajaxPost( { plugins: plugins, categories_add: categories_add, categories_remove: categories_remove }, function(data) {        
            if ( data.bulk_edit === 'success' ) {
                location.reload();
            }            
            else {                
                $('.bulk-edit-plugins').remove();
            }
        });
    });

    $('.plugins-php #bulk-action-form').on('click', '.inline-edit-save .button.cancel', function(e){
        $('.bulk-edit-plugins').remove();
    });

    $('#category-filter-select').on('change', function(e){
        var value = e.currentTarget.value;        
        var searchParams = new URLSearchParams(location.search);
        if ( value !== '0' ) {
            searchParams.set('category_name', value);            
        }
        else {
            searchParams.delete('category_name');            
        }
        location.search = searchParams.toString();
    });

    $('#column-sort-select').on('change', function(e){        
        var value = e.currentTarget.value;
        var searchParams = new URLSearchParams(location.search);        
        
        if ( value !== '0' ) {
            var order = 'desc';            
            if ( value === 'name' || value === 'categories' ) {
                order = 'asc';
            }
            var currentOrderby = searchParams.get('orderby');
            if ( currentOrderby && value === currentOrderby ) {
                var currentOrder = searchParams.get('order');
                order = currentOrder === 'desc' ? 'asc': 'asc';
            }
            searchParams.set('orderby', value);
            searchParams.set('order', order);
        }
        else {
            searchParams.delete('orderby');
            searchParams.delete('order');
        }
        
        location.search = searchParams.toString();        
    });

    $(document).on('click', '.pc-delcat', function(e){
        var container = $(this).parent();
        var category = container.attr('id').replace('category-admin-','');
        var categories = getCategories();
        var index = categories.indexOf(category);
        if ( index > -1 ) {
            categories.splice(index, 1);
            pcvars.categories = categories.join(',');
            $('#pcadmin-categories').val( pcvars.categories );
            container.remove();
        }        
    });

    $(document).on('click', '.pc-cat-toggle', function(e){
        $(this).toggleClass('catfeature-enabled');
        var form = $(this).closest('form');
        var category = $(this).parent().attr('id').replace('category-admin-', '');
        var typeToggle = 'pinned';
        if ( $(this).is('.pc-cat-hide') ) {
            typeToggle = 'hidden';
        }
        else if ( $(this).is('.pc-cat-warning') ) {
            typeToggle = 'warning';
        }
        else if ( $(this).is('.pc-cat-noupdate') ) {
            typeToggle = 'noupdate';
        }

        var arr = categoryFeatures[typeToggle];

        if ( $(this).is('.catfeature-enabled') ) {
            if ( arr.indexOf(category) === -1 ) {
                arr.push(category);
            }            
        }
        else {
            var index = arr.indexOf(category);
            if ( index > -1 ) {
                arr.splice(index, 1);
            }            
        }

        var pars = arr.length > 0 ? arr.join(',') : '';        
        $('input[name=category_'+typeToggle+']', form).remove();
        form.append( '<input type="hidden" name="category_'+typeToggle+'" value="'+pars+'">' );        
    });

    $('.pc-category-admin-add .button').on('click', function(e){
        var input = $(this).siblings('input');
        var category = input.val();
        category = $("<div/>").html( $.trim(category) ).text(); 
        var categories = getCategories();
        if ( categories.indexOf(category) === -1 ) {
            $('.pc-category-admin').append( catItemTemplate( { category: category } ) );
            categories.push(category);
            pcvars.categories = categories.join(',');            
            $('#pcadmin-categories').val( pcvars.categories );
            input.val('');
            $('#pc-nocats').remove();
        }        
    });

    function showOptions( colName, position ) {        
        colName = colName || 'column';
        var dialogWidth = optionsDialog.width();

        var extraOffset = dialogWidth + 60;
        var topOffset = position.top - 20;
        var leftOffset = position.left - extraOffset;

        if ( ( position.left + ( dialogWidth/2 - 60 ) ) >= $('#wpbody').width() ) {            
            leftOffset = $('#wpbody').width() - extraOffset;            
        }
        else if ( leftOffset < 30 ) {
            leftOffset = 30;
        }

        if ( catChanged ) {
            var cats = getCategories();
            var catList = $('.pc-category-admin');
            var html = '', pinned = '', hidden = '', warning = '', noupdate = '', enabledClass = ' catfeature-enabled';                
            $.each(cats, function( index, category ) {
                pinned = (categoryFeatures.pinned.indexOf(category)!==-1)?enabledClass:'';
                hidden = (categoryFeatures.hidden.indexOf(category)!==-1)?enabledClass:'';
                warning = (categoryFeatures.warning.indexOf(category)!==-1)?enabledClass:'';
                noupdate = (categoryFeatures.noupdate.indexOf(category)!==-1)?enabledClass:'';
                html += catItemTemplate( { category: category, pinned: pinned, hidden: hidden, warning: warning, noupdate: noupdate } );
            });
            catList.html(html);
            $('#pcadmin-categories').val( pcvars.categories );
            catChanged = false;
        }

        $('.hide-column-tog').each(function(index, el){
            var col = $(this).val();
            if ( $(this).is(':checked') ) {
                var cb = $('[name='+col+']', columnsOptions);
                if ( ! cb.is(':checked') ) {
                    cb.prop('checked', true);
                }
            }
        });

        if ( optionsDialog.is(':visible') ) {
            optionsDialog.hide();
        }
        else {            
            $('.pc-column-admin li', columnsOptions).hide();
            $('#adv-settings .hide-column-tog').each(function(index, el){ 
                var col = el.value;
                var cb = $('[name='+col+']', columnsOptions);  
                if ( cb.length ) {
                    if ( $(el).is(':checked') ) {
                        cb.prop('checked', true);
                    }
                    else {
                        cb.prop('checked', false);
                    }
                    cb.closest('li').show();
                }                
            });
                       
            if ( stickyEl.is('.ptfixed') ) {
                optionsDialog.css({ top: '60px', left: position.left, position: 'fixed' });
            }
            else {
                optionsDialog.css({ top: topOffset, left: leftOffset, position: 'absolute' });
            }
            
            optionsDialog.show();
            modalOverlay.show();
        }   
    }

    $(document).on('contextmenu click', '.wp-list-table.widefat.plugins thead th', function(e){        
        if ( $('body').is('.mobile') || ( e.type === 'click' && !e.ctrlKey && !e.metaKey ) ) return;
        e.preventDefault();
        var columnsTab = $('#pc-tab-columns-link');
        if ( e.tab && e.tab === 'options' ) {
            $('#pc-tab-options-link').trigger('click');
        }
        else if ( ! columnsTab.is('.active') ) {
            columnsTab.trigger('click');
        }        
        var colName = e.currentTarget.id;
        showOptions( colName, { top: e.pageY, left: e.pageX } );
    });

    $('#sopc-options-button').on('click', function(e){
        $('.wp-list-table.widefat.plugins thead th.column-name').trigger({ 
            type: 'contextmenu',
            pageY: e.pageY + 10,
            pageX: e.pageX + 20,
            tab: 'options' 
        });
    });

    $('.pc-cat-cancel,.pc-modal-overlay').on('click contextmenu', function(e){
        e.preventDefault();
        if ( select2Container.is(':visible') ) {
            select2Container.hide();
            $('.pc-cat-cancel').css('display', '');
            $('.dashicons-yes').removeClass('dashicons-yes').css('display', '');
        }        
         else if ( optionsDialog.is(':visible') ) {
            optionsDialog.hide();
        }

        modalOverlay.hide();        
    });

    $('.pc-col-checkbox', columnsOptions).on('change', function(e){
        var name = $(this).attr('name');
        $('.hide-column-tog[value='+name+']').trigger('click');        
    });

    $('.pc-options-dialog #pc-category-edit-form').on('submit', function(e){
        if ( $(document.activeElement).attr('type') !== 'submit' ) {
            e.preventDefault();
            if ( $('#pc-cat-add-input').val() !== '' ) {
                $('.pc-category-admin-add .button').trigger('click');
            }
        }       
    });
    
    optionsTabs.on('click', function(e){        
        if ( ! $(this).is('.active') ) {
            var id = $(this).attr('id');            
            $('.pc-tab', optionsDialog).hide();
            $(this).addClass('active').siblings().removeClass('active');        
            $('.'+id.replace('-link', ''), optionsDialog).show();
            $('#pc-clear-form,#pc-export-form').hide();
            
            if ( $(this).is('#pc-tab-options-link') ) {
                var position = optionsDialog.position();
                var dialogWidth = 255;                
                if ( ( position.left + dialogWidth ) >= $('#wpbody').width() ) {
                    var leftOffset = $('#wpbody').width() - ( dialogWidth + 20 );
                    optionsDialog.css({ left: leftOffset });                    
                }
            }
        }
    }); 

    // Reload the plugins page on search cancel.
    $('input#plugin-search-input').on('search', function (e) {
        if ( $(this).val() === '' ) {           
           location.href = pcvars.pluginsUrl;
        }
    });
    
    $('a#pc-import').on('click', function(e){
        $('#pc-import-input').trigger('click');
    });

    $('#pc-import-input').on('change', function() {
        if ( ! this.files[0] ) return;
        var file = this.files[0];
        if ( file.name.endsWith('.json') ) {            
            $('#pc-import-form')[0].submit();
        }        
    });

    $('a#pc-export').on('click', function(){        
        if ( pcvars.categories === '' ) {
            $('#pc-export-form')[0].submit();
            $('#pc-export-form').hide();            
        }
        else {
            $('#pc-export-form').toggle();
        }        
    });

    $('a#pc-backup').on('click', function(){
        $('#pc-backup-form')[0].submit();
    });

    $('a#pc-option-clear').on('click', function(){
        $('#pc-clear-form').toggle();
    });

    $('.custom-plugin-view .install-now').on( 'click', function( event ) {
        var $button = $( event.target );
        event.preventDefault();

        if ( $button.hasClass( 'updating-message' ) || $button.hasClass( 'button-disabled' ) ) {
            return;
        }

        if ( wp.updates.shouldRequestFilesystemCredentials && ! wp.updates.ajaxLocked ) {
            wp.updates.requestFilesystemCredentials( event );

            $document.on( 'credential-modal-cancel', function() {
                var $message = $( '.install-now.updating-message' );

                $message
                    .removeClass( 'updating-message' )
                    .text( wp.updates.l10n.installNow );

                wp.a11y.speak( wp.updates.l10n.updateCancel, 'polite' );
            } );
        }

        wp.updates.installPlugin( {
            slug: $button.data( 'slug' )
        } );
    } );

    $(document).on('wp-plugin-install-success', function(){
        var pluginLink = $('.custom-plugin-view .install-now.updated-message.installed');
        if ( pluginLink.length ) {
            var view = pluginLink.is('.cv-action-imported') ? 'imported': 'trash';
            pluginLink.closest('tr').remove();
            adjustCounter(view);
        }        
    });

    $(document).on('wp-plugin-deleting', function(args){
        if ( $('.subsubsub li.trash').length === 0 ) {            
            $('.subsubsub').append( wp.template( 'trash-view-link' ) );
        }
        else {
            adjustCounter('trash', 'add');
        }
    });

    $('.custom-plugin-view .pcremove').on('click', function(){        
        var pluginLink = $(this);
        var pluginRow = pluginLink.closest('tr');
        var view = pluginLink.is('.cv-action-imported') ? 'imported': 'trash'; 
        ajaxPost( { plugin_remove: $(this).data('plugin'), pcview: view }, function(data) {            
            if ( data.success ) {
                pluginRow.remove();
                adjustCounter(view);
            }
        });
    });

    if ( pcvars.hideFeedbackDialog ) {
        $('.wp-list-table.plugins span.deactivate a').not('a.pc-deactivate-warning').off('click');
        $('.wp-list-table.plugins span.deactivate a').on('click', function(e){
            e.stopPropagation();
            if ( $(this).is('.pc-deactivate-warning') && pcvars.deactivateWarning && ! window.confirm( pcvars.deactivateWarning ) ) {            
                e.preventDefault();
            }
        });
    }
    else {
        $('.row-actions .deactivate > a.pc-deactivate-warning').on('click', function(e){        
            if ( pcvars.deactivateWarning && ! window.confirm( pcvars.deactivateWarning ) ) {            
                e.preventDefault();
            }
        });
    }

    $('#pc-category-empty-trash').on('click', function(e){
        e.preventDefault();
        e.stopPropagation();        
        $('#pc-empty-trash-form')[0].submit();
    }); 

    if ( pcvars.categoryName || pcvars.orderby ) {        
        $('.row-actions .activate > a,.row-actions .deactivate > a,.row-actions .delete > a').on('mouseenter', function(e){
            var url = $(this).attr('href'); 
            var urlArr = url.split('?');
            if ( urlArr.length < 2 ) return;           
            var urlParams = new URLSearchParams( urlArr.pop() );
            var orderby = urlParams.get('orderby');
            var categoryName = urlParams.get('category_name');
            if ( !categoryName && !orderby ) {
                if ( pcvars.categoryName ) {
                    urlParams.set('category_name', pcvars.categoryName);
                }
                if ( pcvars.orderby ) {
                    var searchParams = new URLSearchParams(location.search);
                    var order = searchParams.get('order');
                    order = order === 'asc' ? 'asc' : 'desc';
                    urlParams.set('orderby', pcvars.orderby);
                    urlParams.set('order', order);
                }                
                $(this).attr('href', urlArr[0] + '?' + urlParams.toString());
            }            
        });
    }
    
    var metaPluginArrays = [];

    if ( pcvars.metaInfoPlugins ) {
        var pluginList = JSON.parse( pcvars.metaInfoPlugins );
        var metaPlugins = pluginList, numPlugins = metaPlugins.length;        
        if ( numPlugins > 0 ) {            
            $('body').append( '<div id="meta-info-ajax-dialog"><h2>Getting metadata for plugins</h2><span class="get-meta-spinner"></span><div class="pc-number-progression"><span>0</span> / '+numPlugins+'</div></div>' );
            modalOverlay.off('click contextmenu').css({ opacity: 0.6 }).show();
            var i, chunk = 10;
            for ( i = 0; i < numPlugins; i += chunk ) {
                metaPluginArrays.push( metaPlugins.slice( i, i+chunk ) );                
            }

            if ( metaPluginArrays.length > 0 ) {                
                getPluginMeta( metaPluginArrays.length-1 );
            }
        }        
    }    

    function getPluginMeta( numArray ) {
        if ( numArray >= 0 ) {
            ajaxPost( { get_plugin_meta: metaPluginArrays[numArray], plugin_meta_update: true }, function(data) {
                if ( data.success ) {
                    var numSpan = $('.pc-number-progression > span');
                    var numFetched = parseInt( numSpan.text() );
                    numSpan.text( numFetched + data.result.length );
                    getPluginMeta( numArray-1 );
                }
                else {
                    window.location.reload();
                }
            });
        }
        else {
            window.location.reload();
        }
    }

    $(document).on('click', '.pc-meta-update', function(e){
        var form = $('#pc-update-meta-form');
        if ( e.ctrlKey ) {
            form.append('<input type="hidden" value="true" name="pc-update-meta-ctrl">');
        }
        form[0].submit();
    });

    // Helper functions

    function ajaxPost( pars, callback ) {
        var parameters = $.extend({}, { action: 'plugin_columns_action', _ajax_nonce: pcvars.nonce }, pars);
        if ( pcvars.pluginView !== 'all' ) {
            parameters['plugin_status'] = pcvars.pluginView;
        }
        $.post(ajaxurl, parameters, callback );
    }

    function setStickyColumnWidth() {        
        if ( pcvars.stickyHeader )  {            
            var style = '', isSticky = false;            
            if ( stickyEl.is('.ptfixed') ) {
                stickyEl.removeClass('ptfixed');
                isSticky = true;
            }
            $('thead > tr > th:not(:hidden)', pluginTable).each(function(index, el){                
                style += '.ptfixed th#'+ $(el).attr('id') +'{ width: '+ $(el).width() +'px; }';
            });
            style += '.ptfixed { width: '+ pluginTable.width() +'px; }';
            if ( $('body #ptfixed-styles').length < 1 ) {
                $('body').append('<style id="ptfixed-styles"></style>');
            }
            if ( isSticky ) {
                stickyEl.addClass('ptfixed');
            }
            $('body #ptfixed-styles').html(style);
        }
    }

    function getCategories() {
        return getArray(pcvars.categories);
    }

    function getArray( array ) {
        return array ? array.split(',') : [];
    }

    function getForm( form ) {
		return form.serializeArray().reduce(function(obj, item) {
            if ( obj[item.name] ) obj[item.name] = obj[item.name]+','+item.value;
            else obj[item.name] = item.value;
            return obj;
        }, {});
    }

    function adjustCounter( view, operator ) {
        operator = operator || 'subtract';
        var el;
        if ( view === 'imported' ) {
            el = $('#imported-count');
        }
        else {
            el = $('#trash-count');
        }
        if ( el.length ) {
            var count = parseInt( el.text() );
            count = ( operator === 'subtract' ) ? (count - 1) : (count + 1);
            
            if ( count >= 0 ) {
                el.text( count );
            }
            if ( count === 0 ) {                
                location.search = '';
            }
        }        
    }    
    
});
