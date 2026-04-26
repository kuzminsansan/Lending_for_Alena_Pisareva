const LEADS_STORAGE_KEY = "alena-pisareva-demo-leads";
const DEMO_SEEDED_KEY = "alena-pisareva-demo-seeded";

const statusLabels = {
  new: "Новая",
  contacted: "Связались",
  scheduled: "Записана",
  completed: "Завершена",
};

function readLeads() {
  try {
    return JSON.parse(localStorage.getItem(LEADS_STORAGE_KEY)) || [];
  } catch (error) {
    return [];
  }
}

function saveLeads(leads) {
  localStorage.setItem(LEADS_STORAGE_KEY, JSON.stringify(leads));
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatDate(value) {
  return new Intl.DateTimeFormat("ru-RU", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

function createLead({ name, phone, message }) {
  return {
    id: `lead-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    name,
    phone,
    message,
    status: "new",
    note: "",
    createdAt: new Date().toISOString(),
  };
}

async function notifyVkAboutLead(lead) {
  const response = await fetch("send-vk.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(lead),
  });

  if (!response.ok) {
    throw new Error("VK notification failed");
  }
}

function initLeadForm() {
  const form = document.querySelector("#lead-form");
  const status = document.querySelector("#lead-form-status");

  if (!form) {
    return;
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const formData = new FormData(form);
    const lead = createLead({
      name: String(formData.get("name") || "").trim(),
      phone: String(formData.get("phone") || "").trim(),
      message: String(formData.get("message") || "").trim(),
    });

    const leads = readLeads();
    saveLeads([lead, ...leads]);
    form.reset();

    if (status) {
      status.textContent = "Заявка сохранена в демо-админке. Отправляем уведомление во ВКонтакте...";
    }

    try {
      await notifyVkAboutLead(lead);

      if (status) {
        status.textContent = "Заявка сохранена, уведомление во ВКонтакте отправлено.";
      }
    } catch (error) {
      if (status) {
        status.textContent = "Заявка сохранена в демо-админке. Уведомление во ВКонтакте заработает после настройки PHP-файла на хостинге.";
      }
    }
  });
}

function ensureDemoLeads() {
  const leads = readLeads();
  const isDemoSeeded = localStorage.getItem(DEMO_SEEDED_KEY) === "true";

  if (leads.length || isDemoSeeded) {
    return leads;
  }

  const demoLeads = [
    {
      id: "demo-1",
      name: "Марина",
      phone: "+7 912 345-67-89",
      message: "Хочу записаться на первую консультацию, удобнее вечером.",
      status: "new",
      note: "Перезвонить после 18:00.",
      createdAt: new Date(Date.now() - 1000 * 60 * 24).toISOString(),
    },
    {
      id: "demo-2",
      name: "Елена",
      phone: "+7 999 222-11-00",
      message: "Интересует консультация для пары.",
      status: "contacted",
      note: "Уточнить формат: онлайн или очно.",
      createdAt: new Date(Date.now() - 1000 * 60 * 90).toISOString(),
    },
  ];

  saveLeads(demoLeads);
  localStorage.setItem(DEMO_SEEDED_KEY, "true");
  return demoLeads;
}

function getStatusClass(status) {
  return `status-badge status-${status}`;
}

function renderLeads() {
  const list = document.querySelector("#admin-leads");
  const empty = document.querySelector("#admin-empty");
  const counter = document.querySelector("#admin-counter");
  const leads = ensureDemoLeads();

  if (!list) {
    return;
  }

  list.innerHTML = "";

  if (counter) {
    counter.textContent = `${leads.length} ${leads.length === 1 ? "заявка" : "заявки"}`;
  }

  if (empty) {
    empty.hidden = leads.length > 0;
  }

  leads.forEach((lead) => {
    const safeId = escapeHtml(lead.id);
    const safeName = escapeHtml(lead.name);
    const safePhone = escapeHtml(lead.phone);
    const safeMessage = escapeHtml(lead.message || "Комментарий не указан.");
    const safeNote = escapeHtml(lead.note || "");
    const safeStatus = statusLabels[lead.status] ? lead.status : "new";
    const card = document.createElement("article");
    card.className = "admin-lead-card";
    card.innerHTML = `
      <div class="admin-lead-top">
        <div>
          <p class="admin-lead-date">${formatDate(lead.createdAt)}</p>
          <h2>${safeName}</h2>
        </div>
        <span class="${getStatusClass(safeStatus)}">${statusLabels[safeStatus]}</span>
      </div>
      <div class="admin-lead-contact">
        <a href="tel:${safePhone}">${safePhone}</a>
      </div>
      <p class="admin-lead-message">${safeMessage}</p>
      <label class="admin-field">
        Стадия обработки
        <select data-action="status" data-id="${safeId}">
          ${Object.entries(statusLabels)
            .map(([value, label]) => `<option value="${value}" ${safeStatus === value ? "selected" : ""}>${label}</option>`)
            .join("")}
        </select>
      </label>
      <label class="admin-field">
        Заметка администратора
        <textarea data-action="note" data-id="${safeId}" rows="3" placeholder="Например: договорились на вторник">${safeNote}</textarea>
      </label>
      <button class="admin-delete" type="button" data-action="delete" data-id="${safeId}">Удалить заявку</button>
    `;
    list.append(card);
  });
}

function updateLead(id, patch) {
  const leads = readLeads().map((lead) => (lead.id === id ? { ...lead, ...patch } : lead));
  saveLeads(leads);
  renderLeads();
}

function deleteLead(id) {
  const leads = readLeads().filter((lead) => lead.id !== id);
  saveLeads(leads);
  renderLeads();
}

function initAdmin() {
  const list = document.querySelector("#admin-leads");
  const resetButton = document.querySelector("#admin-reset");

  if (!list) {
    return;
  }

  renderLeads();

  list.addEventListener("change", (event) => {
    const target = event.target;

    if (target.dataset.action === "status") {
      updateLead(target.dataset.id, { status: target.value });
    }
  });

  list.addEventListener("input", (event) => {
    const target = event.target;

    if (target.dataset.action === "note") {
      updateLead(target.dataset.id, { note: target.value });
    }
  });

  list.addEventListener("click", (event) => {
    const target = event.target;

    if (target.dataset.action === "delete") {
      deleteLead(target.dataset.id);
    }
  });

  if (resetButton) {
    resetButton.addEventListener("click", () => {
      localStorage.removeItem(LEADS_STORAGE_KEY);
      localStorage.removeItem(DEMO_SEEDED_KEY);
      renderLeads();
    });
  }
}

initLeadForm();
initAdmin();
