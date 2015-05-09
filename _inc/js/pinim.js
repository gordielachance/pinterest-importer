jQuery(document).ready(function($){
    
    //handle board categories
    var boardCats = $('#the-list .column-category input[type="radio"]');
    boardCats.pinimBoardCats(); //init
    boardCats.click(function(e) {
        $(this).pinimBoardCats();
    });
    
    //update pins confirm
    $('.row-actions .update a, .tablenav #update_all_bt').click(function(e) {
        r = confirm(pinimL10n.update_warning);
        if (r == false) {
            e.preventDefault();
        }
    });
    $('.tablenav .bulkactions #doaction').click(function(e) {
        var container = $(this).parents('.bulkactions');
        var select = container.find('select');
        var selected = select.val();
        if (selected == 'pins_update_pins'){
            r = confirm(pinimL10n.update_warning);
            if (r == false) {
                e.preventDefault();
            }
        }
    });
    

});


(function($) {
    $.fn.pinimBoardCats = function() {
        return this.each(function() {
            var cell = $( this ).parents('.column-category');
            var auto = cell.find('input[type="radio"][value="auto"]');
            var select = cell.find('select');
            if (auto.is(':checked')){
                select.addClass('hidden');
            }else{
                select.removeClass('hidden');
            }
        });
    };
}(jQuery));

