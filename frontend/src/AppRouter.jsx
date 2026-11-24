import { useEffect, useState } from "react";
import App from "./App.jsx";
import Login from "./pages/auth/Login.jsx";
import Register from "./pages/auth/Register.jsx";
import Dashboard from "./pages/dashboard/Dashboard.jsx";
import UploadModel from "./pages/models/UploadModel.jsx";
import ModelDetails from "./pages/models/ModelDetails.jsx";

export default function AppRouter() {
  const [path, setPath] = useState(window.location.pathname);

  useEffect(() => {
    const handleNavigation = () => {
      setPath(window.location.pathname);
    };

    window.addEventListener("popstate", handleNavigation);

    return () => {
      window.removeEventListener(
        "popstate",
        handleNavigation
      );
    };
  }, []);

  if (path === "/login") {
    return <Login />;
  }

  if (path === "/register") {
    return <Register />;
  }

  if (path === "/dashboard") {
    return <Dashboard />;
  }

  if (path === "/models/upload") {
    return <UploadModel />;
  }

  if (path === "/models/view") {
    return <ModelDetails />;
  }

  return <App />;
}