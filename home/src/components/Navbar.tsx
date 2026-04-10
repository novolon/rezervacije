import React, { useState } from 'react';
import { motion } from 'framer-motion';
import { Menu, X } from 'lucide-react';

const APP_URL = import.meta.env.VITE_APP_URL ?? '';

export function Navbar() {
  const [isOpen, setIsOpen] = useState(false);
  return (
    <nav className="fixed top-0 left-0 right-0 z-50 bg-cream/90 backdrop-blur-md border-b border-sage-light">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex justify-between items-center h-20">
          <div className="flex-shrink-0 flex items-center">
            <a
              href="#"
              className="text-2xl font-bold text-forest tracking-tight">
              Rezervacije<span className="text-terracotta">.</span>
            </a>
          </div>

          <div className="hidden md:flex items-center space-x-8">
            <a
              href="#features"
              className="text-forest/80 hover:text-forest font-medium transition-colors">
              Features
            </a>
            <a
              href="#pricing"
              className="text-forest/80 hover:text-forest font-medium transition-colors">
              Pricing
            </a>
            <a
              href="#faq"
              className="text-forest/80 hover:text-forest font-medium transition-colors">
              FAQ
            </a>
            <a
              href={`${APP_URL}/login.php`}
              className="text-forest/80 hover:text-forest font-medium transition-colors">
              Prijava
            </a>
            <a
              href={`${APP_URL}/register.php`}
              className="bg-terracotta hover:bg-terracotta-hover text-white px-6 py-2.5 rounded-full font-medium transition-colors shadow-sm">
              Start Free Trial
            </a>
          </div>

          <div className="md:hidden flex items-center">
            <button
              onClick={() => setIsOpen(!isOpen)}
              className="text-forest p-2">
              {isOpen ? <X size={24} /> : <Menu size={24} />}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile menu */}
      {isOpen &&
        <motion.div
          initial={{ opacity: 0, y: -10 }}
          animate={{ opacity: 1, y: 0 }}
          className="md:hidden bg-cream border-b border-sage-light">
          <div className="px-4 pt-2 pb-6 space-y-4 flex flex-col">
            <a
              href="#features"
              onClick={() => setIsOpen(false)}
              className="text-forest font-medium py-2">
              Features
            </a>
            <a
              href="#pricing"
              onClick={() => setIsOpen(false)}
              className="text-forest font-medium py-2">
              Pricing
            </a>
            <a
              href="#faq"
              onClick={() => setIsOpen(false)}
              className="text-forest font-medium py-2">
              FAQ
            </a>
            <a
              href={`${APP_URL}/login.php`}
              className="text-forest font-medium py-2">
              Prijava za uporabnike
            </a>
            <a
              href={`${APP_URL}/register.php`}
              onClick={() => setIsOpen(false)}
              className="bg-terracotta text-white px-6 py-3 rounded-full font-medium text-center mt-4">
              Start Free Trial
            </a>
          </div>
        </motion.div>
      }
    </nav>);
}
