import { Toaster } from "sonner";

import { TooltipProvider } from "@/components/ui/tooltip";
import { ContentWorkspace } from "@/content/ContentWorkspace";

export function App() {
  return (
    <TooltipProvider>
      <ContentWorkspace />

      <Toaster />
    </TooltipProvider>
  );
}
