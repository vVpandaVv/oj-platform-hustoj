(() => {
  "use strict";

  const accountToggle = document.querySelector("[data-account-toggle]");
  const accountMenu = document.querySelector("[data-account-menu]");
  const secondaryToggle = document.querySelector("[data-secondary-toggle]");
  const secondaryMenu = document.querySelector("[data-secondary-menu]");
  const mobileSecondaryToggle = document.querySelector("[data-mobile-secondary-toggle]");
  const mobileSecondaryMenu = document.querySelector("[data-mobile-secondary-menu]");
  const mobileMoreToggle = document.querySelector("[data-mobile-more-toggle]");
  const mobileMoreMenu = document.querySelector("[data-mobile-more-menu]");

  const closeMenu = (toggle, menu) => {
    if (!toggle || !menu) {
      return;
    }

    menu.hidden = true;
    toggle.setAttribute("aria-expanded", "false");
  };

  const toggleMenu = (toggle, menu) => {
    const expanded = toggle.getAttribute("aria-expanded") === "true";
    toggle.setAttribute("aria-expanded", String(!expanded));
    menu.hidden = expanded;
  };

  if (accountToggle && accountMenu) {
    accountToggle.addEventListener("click", () => {
      closeMenu(secondaryToggle, secondaryMenu);
      toggleMenu(accountToggle, accountMenu);
    });

    document.addEventListener("click", (event) => {
      if (accountMenu.hidden || accountMenu.contains(event.target) || accountToggle.contains(event.target)) {
        return;
      }

      accountMenu.hidden = true;
      accountToggle.setAttribute("aria-expanded", "false");
    });
  }

  if (secondaryToggle && secondaryMenu) {
    secondaryToggle.addEventListener("click", () => {
      closeMenu(accountToggle, accountMenu);
      toggleMenu(secondaryToggle, secondaryMenu);
    });

    document.addEventListener("click", (event) => {
      if (secondaryMenu.hidden || secondaryMenu.contains(event.target) || secondaryToggle.contains(event.target)) {
        return;
      }

      closeMenu(secondaryToggle, secondaryMenu);
    });
  }

  if (mobileSecondaryToggle && mobileSecondaryMenu) {
    mobileSecondaryToggle.addEventListener("click", (event) => {
      event.preventDefault();
      closeMenu(mobileMoreToggle, mobileMoreMenu);
      toggleMenu(mobileSecondaryToggle, mobileSecondaryMenu);
    });

    document.addEventListener("click", (event) => {
      if (mobileSecondaryMenu.hidden || mobileSecondaryMenu.contains(event.target) || mobileSecondaryToggle.contains(event.target)) {
        return;
      }

      closeMenu(mobileSecondaryToggle, mobileSecondaryMenu);
    });
  }

  if (mobileMoreToggle && mobileMoreMenu) {
    mobileMoreToggle.addEventListener("click", (event) => {
      event.preventDefault();
      closeMenu(mobileSecondaryToggle, mobileSecondaryMenu);
      toggleMenu(mobileMoreToggle, mobileMoreMenu);
    });

    document.addEventListener("click", (event) => {
      if (mobileMoreMenu.hidden || mobileMoreMenu.contains(event.target) || mobileMoreToggle.contains(event.target)) {
        return;
      }

      closeMenu(mobileMoreToggle, mobileMoreMenu);
    });
  }

  const syncShellPreview = (screenId) => {
    document.querySelectorAll("[data-admin-sidebar]").forEach((sidebar) => {
      sidebar.hidden = sidebar.dataset.adminSidebar !== screenId;
    });

    document.querySelectorAll("[data-admin-console-link]").forEach((link) => {
      link.hidden = screenId !== "instructor-home";
    });

    if (accountMenu && accountToggle) {
      closeMenu(accountToggle, accountMenu);
    }

    if (secondaryMenu && secondaryToggle) {
      closeMenu(secondaryToggle, secondaryMenu);
    }

    if (mobileSecondaryMenu && mobileSecondaryToggle) {
      closeMenu(mobileSecondaryToggle, mobileSecondaryMenu);
    }

    if (mobileMoreMenu && mobileMoreToggle) {
      closeMenu(mobileMoreToggle, mobileMoreMenu);
    }
  };

  document.querySelectorAll("[data-screen-target]").forEach((button) => {
    button.addEventListener("click", () => syncShellPreview(button.dataset.screenTarget));
  });

  const initialScreen = window.location.hash.slice(1)
    || document.querySelector("[data-screen]")?.dataset.screen
    || "";

  syncShellPreview(initialScreen);
})();
