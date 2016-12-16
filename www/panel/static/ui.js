ui = {}

ui.previewJSON = function(el) {
    var str = $(el).find('code').text();
    var html =
        '<pre>' +
        JSON.stringify(JSON.parse(str), null, 4) +
        '</pre>'
    bootbox.alert({
        message: html,
        size: 'large'
    });
}


ui.toggleExpander = function(el) {
    $(el).next('pre').toggle();
}