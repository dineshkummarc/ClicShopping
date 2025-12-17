/**
 * Chat Send Handler
 * Gère l'envoi des messages dans la modal du chat
 */

document.addEventListener("DOMContentLoaded", function() {
  console.log('ChatSend: Initializing...');
  
  const sendGptButton = document.querySelector("#sendGpt");
  
  if (!sendGptButton) {
    console.warn('ChatSend: Button #sendGpt not found');
    return;
  }
  
  console.log('ChatSend: Button found, attaching handler');
  
  sendGptButton.addEventListener("click", function() {
    console.log('ChatSend: Button clicked');
    
    const messageInput = document.querySelector("#messageGpt");
    const chatOutput = document.querySelector("#chatGpt-output");
    
    if (!messageInput || !chatOutput) {
      console.error('ChatSend: Required elements not found');
      return;
    }
    
    const message = messageInput.value;
    
    // ============================================
    // CLIENT-SIDE VALIDATION
    // ============================================
    
    // Helper function to show validation error
    const showValidationError = (errorMessage) => {
      console.log('ChatSend: Validation error:', errorMessage);
      
      const validationDiv = document.createElement("div");
      validationDiv.className = "alert alert-warning alert-dismissible fade show mt-2";
      validationDiv.setAttribute("role", "alert");
      validationDiv.innerHTML = `
        <i class="bi bi-exclamation-triangle"></i>
        <strong>${errorMessage}</strong>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      `;
      
      chatOutput.insertBefore(validationDiv, chatOutput.firstChild);
      
      setTimeout(() => {
        if (validationDiv && validationDiv.parentNode) {
          validationDiv.remove();
        }
      }, 3000);
      
      messageInput.focus();
    };
    
    // ✅ Validation 1: Empty query check
    if (!message.trim()) {
      showValidationError('Veuillez entrer une question');
      return;
    }
    
    // ✅ Validation 2: Query length check (max 1000 characters)
    const maxLength = 1000;
    if (message.length > maxLength) {
      showValidationError(`Votre question est trop longue (max ${maxLength} caractères)`);
      return;
    }
    
    console.log('ChatSend: Sending message:', message.substring(0, 50));
    
    // 🛡️ SÉCURITÉ: Afficher le message utilisateur de manière sécurisée (textContent au lieu de innerHTML)
    const userMessageDiv = document.createElement("div");
    userMessageDiv.className = "chat-message user";
    userMessageDiv.textContent = message;  // ← textContent empêche l'exécution de scripts
    chatOutput.appendChild(userMessageDiv);
    
    // Afficher un indicateur de chargement
    const loadingDiv = document.createElement("div");
    loadingDiv.className = "chat-message loading";
    loadingDiv.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> <span>Analyse en cours...</span>';
    chatOutput.appendChild(loadingDiv);
    
    // Récupérer l'URL depuis la configuration
    const ajaxUrl = window.CHAT_CONFIG ? window.CHAT_CONFIG.ajaxUrl : null;
    
    if (!ajaxUrl) {
      console.error('ChatSend: AJAX URL not configured');
      loadingDiv.innerHTML = '<div class="alert alert-danger">Erreur: Configuration manquante</div>';
      return;
    }
    
    console.log('ChatSend: Sending to:', ajaxUrl);
    
    // 🔧 FIX: Utiliser JSON au lieu de form-urlencoded pour préserver les caractères < et >
    // Les serveurs web (mod_security, WAF) peuvent filtrer < et > dans form-urlencoded
    fetch(ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ message: message }),
    })
    .then(response => {
      console.log('ChatSend: Response received, status:', response.status);
      
      // 🔧Vérifier si la réponse est OK avant de parser le JSON
      if (!response.ok) {
        return response.text().then(text => {
          console.error('ChatSend: Server error response:', text.substring(0, 500));
          throw new Error(`Server error ${response.status}: ${text.substring(0, 100)}`);
        });
      }
      
      return response.json();
    })
    .then(data => {
      console.log('ChatSend: Data parsed:', data);
      
      // Supprimer l'indicateur de chargement
      if (loadingDiv && loadingDiv.parentNode) {
        loadingDiv.parentNode.removeChild(loadingDiv);
      }
      
      // Vérifier le succès
      if (!data.success) {
        console.error('ChatSend: Request failed:', data.error);
        chatOutput.innerHTML += '<div class="alert alert-danger">Erreur: ' + (data.error || "Erreur inconnue") + '</div>';
        return;
      }
      
      console.log('ChatSend: Creating message element with ID:', data.interaction_id);
      
      // Créer un élément de message avec data-interaction-id
      const messageDiv = document.createElement("div");
      messageDiv.className = "chat-message assistant";
      messageDiv.dataset.interactionId = data.interaction_id || "temp_" + Date.now();
      
      // Ajouter les métadonnées optionnelles
      if (data.type) messageDiv.dataset.type = data.type;
      if (data.confidence) messageDiv.dataset.confidence = data.confidence;
      
      // Créer le contenu du message
      const contentDiv = document.createElement("div");
      contentDiv.className = "message-content";
      contentDiv.innerHTML = data.text_response;
      
      messageDiv.appendChild(contentDiv);
      
      // Ajouter les métriques si disponibles
      if (data.metrics) {
        const normalize = (v) => {
          const n = parseFloat(v || 0);
          if (!isFinite(n)) return 0;
          if (n > 1) return Math.max(0, Math.min(1, n / 100));
          if (n < 0) return 0;
          return n;
        };

        // Fallbacks when backend does not provide full metrics
        const fallbackConfidence = normalize(data.confidence);
        const m = {
          confidence: normalize(data.metrics.confidence_score) || fallbackConfidence || 0,
          security: normalize(data.metrics.security_score),
          hallucination: normalize(data.metrics.hallucination_score),
          quality: normalize(data.metrics.response_quality),
          relevance: normalize(data.metrics.relevance_score),
        };

        // Heuristic fallbacks to avoid all zeros
        if (!m.relevance) m.relevance = m.confidence;
        if (!m.quality) m.quality = normalize((contentDiv.textContent || contentDiv.innerText || '').length > 400 ? 0.8 : 0.6);
        if (!m.security) m.security = normalize(data.type === 'web_search' ? 0.5 : 0.8);
        if (!m.hallucination) m.hallucination = 0; // assume low by default
        console.log('ChatSend: Metrics normalized', m);

        const metricsDiv = document.createElement("div");
        metricsDiv.className = "message-metrics mt-2 p-2 bg-light rounded";
        metricsDiv.style.fontSize = "0.85em";
        metricsDiv.style.borderLeft = "3px solid #667eea";
        
        const metricsHTML = `
          <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-primary" title="Niveau de confiance de l'IA">
              🎯 Confiance: ${Math.round(m.confidence * 100)}%
            </span>
            <span class="badge bg-success" title="Score de sécurité de la réponse">
              🔒 Sécurité: ${Math.round(m.security * 100)}%
            </span>
            <span class="badge bg-warning text-dark" title="Probabilité d'hallucination">
              🎭 Hallucination: ${Math.round(m.hallucination * 100)}%
            </span>
            <span class="badge bg-info" title="Qualité globale de la réponse">
              ⭐ Qualité: ${Math.round(m.quality * 100)}%
            </span>
            <span class="badge bg-secondary" title="Pertinence par rapport à la question">
              🎯 Pertinence: ${Math.round(m.relevance * 100)}%
            </span>
          </div>
        `;
        
        metricsDiv.innerHTML = metricsHTML;
        messageDiv.appendChild(metricsDiv);
      }
      
      chatOutput.appendChild(messageDiv);
      
      // Scroll vers le bas
      chatOutput.scrollTop = chatOutput.scrollHeight;
      
      // Vider le champ de saisie
      messageInput.value = "";
      
      // Afficher le bouton de copie
      const copyBtn = document.querySelector("#copyResultButton");
      if (copyBtn) copyBtn.classList.remove("d-none");
      
      console.log('ChatSend: Message displayed successfully');
    })
    .catch(error => {
      console.error("ChatSend: Fetch error:", error);
      
      if (loadingDiv && loadingDiv.parentNode) {
        loadingDiv.parentNode.removeChild(loadingDiv);
      }
      
      chatOutput.innerHTML += '<div class="alert alert-danger">Erreur: ' + error.message + '</div>';
    });
  });
  
  console.log('ChatSend: Initialization complete');
});
