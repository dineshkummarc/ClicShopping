$(document).ready(function () {
  $('.toggleButton').on('click', function () {
    const $button = $(this);
    const uniqueId = $button.data('unique-id');
    const productId = $button.data('product-id');
    const customerId = $button.data('customer-id');
    const ajaxUrl = $button.data('ajax-url');
    const isYes = $button.hasClass('yesButton');

    const vote = isYes ? 1 : 0;
    const reviewsId = uniqueId !== 0 ? uniqueId : 0;
    const sentiment = reviewsId === 0 ? vote : 0;

    // Préparation des données à envoyer
    const postData = {
      product_id: productId,
      customer_id: customerId,
      vote: vote,
      reviewId: reviewsId,
      sentiment: sentiment
    };

    // Envoi de la requête AJAX
    $.ajax({
      type: 'POST',
      url: ajaxUrl,
      data: postData,
      success: function (response) {
        // Mise à jour de l'interface utilisateur
        const $yesValue = $(`#${uniqueId}_yesButton`).siblings('.yesValue');
        const $noValue = $(`#${uniqueId}_noButton`).siblings('.noValue');
        const $thankYou = $(`#${uniqueId}_noButton`).siblings('.thankYouMessage');

        if (isYes) {
          const currentYes = parseInt($yesValue.text().replace(/\D/g, ''), 10) || 0;
          $yesValue.text(`(${currentYes + 1})`);
        } else {
          const currentNo = parseInt($noValue.text().replace(/\D/g, ''), 10) || 0;
          $noValue.text(`(${currentNo + 1})`);
        }

        // Affichage du message de remerciement
        $thankYou.show();

        // Désactivation des boutons pour éviter les votes multiples
        $(`#${uniqueId}_yesButton, #${uniqueId}_noButton`).off('click').addClass('disabled');
      },
      error: function (xhr, status, error) {
        console.error('Erreur lors de l\'envoi du vote :', error);
      }
    });
  });
});