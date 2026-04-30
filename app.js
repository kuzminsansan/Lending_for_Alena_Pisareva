function createLead({ name, phone, message }) {
  return {
    id: `lead-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    name,
    phone,
    message,
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

    if (status) {
      status.textContent = "Отправляем заявку...";
    }

    try {
      await notifyVkAboutLead(lead);
      form.reset();

      if (status) {
        status.textContent = "Заявка отправлена. Алёна получит уведомление во ВКонтакте.";
      }
    } catch (error) {
      if (status) {
        status.textContent = "Не удалось отправить заявку. Пожалуйста, позвоните по телефону выше.";
      }
    }
  });
}

initLeadForm();
